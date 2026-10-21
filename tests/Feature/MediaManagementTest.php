<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileGallery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\Support\SyntheticUploads;
use Tests\TestCase;

final class MediaManagementTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(CatalogSeeder::class);
        $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->subMinute()->startOfSecond());
        $this->app->instance(Clock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        SyntheticUploads::cleanup();
        parent::tearDown();
    }

    public function test_avatar_accepts_the_image_field_returns_flat_public_shape_and_replaces_old_binary(): void
    {
        [, $token] = $this->registerAccount('avatar-owner@example.test');
        $profile = $this->createProfile($token, 'Guía Avatar');

        $firstResponse = $this->postAvatar(
            $token,
            $profile,
            SyntheticUploads::file('avatar-inicial.jpg', 'image/jpeg', SyntheticUploads::jpeg()),
        )->assertCreated();
        $first = $firstResponse->json('data');
        $this->assertSame(['id', 'url', 'mime_type', 'width', 'height', 'position'], array_keys($first));
        $this->assertSame('image/jpeg', $first['mime_type']);
        $this->assertSame(1, $first['width']);
        $this->assertSame(1, $first['height']);
        $this->assertSame(0, $first['position']);
        $this->assertStringContainsString('/storage/media/'.$profile->public_id.'/avatar/', $first['url']);
        foreach (['private_path', 'path', 'disk', 'checksum'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $firstResponse->getContent());
        }
        $firstFile = File::query()->where('public_id', $first['id'])->firstOrFail();
        Storage::disk('public')->assertExists($firstFile->private_path);

        $second = $this->postAvatar(
            $token,
            $profile,
            SyntheticUploads::file('avatar-nuevo.png', 'image/png', SyntheticUploads::png()),
        )->assertCreated()->json('data');
        $this->assertSame('image/png', $second['mime_type']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertNotNull($firstFile->refresh()->storage_deleted_at);
        Storage::disk('public')->assertMissing($firstFile->private_path);
        $this->withToken($token)
            ->getJson('/api/v1/profiles/'.$profile->public_id)
            ->assertOk()
            ->assertJsonPath('data.avatar.id', $second['id'])
            ->assertJsonPath('data.avatar.url', $second['url']);
    }

    public function test_avatar_rejects_svg_false_mime_and_oversized_real_dimensions_without_writes(): void
    {
        [, $token] = $this->registerAccount('avatar-validation@example.test');
        $profile = $this->createProfile($token, 'Guía Imagen Segura');

        $invalid = [
            SyntheticUploads::file('vector.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'),
            SyntheticUploads::file('mime-falso.jpg', 'image/png', SyntheticUploads::jpeg()),
        ];
        foreach ($invalid as $file) {
            $this->postAvatar($token, $profile, $file)
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['fields' => ['image']]]);
        }

        config()->set('contactur.uploads.media_max_width', 1);
        $this->postAvatar(
            $token,
            $profile,
            SyntheticUploads::file('ancho-excesivo.png', 'image/png', SyntheticUploads::png(2, 1)),
        )->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['image']]]);

        $this->assertSame(0, File::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_t22_public_images_are_stored_without_exif_gps_xmp_or_text_metadata(): void
    {
        [, $token] = $this->registerAccount('avatar-metadata@example.test');
        $profile = $this->createProfile($token, 'Guía Sin Metadatos');

        // JPEG de teléfono: APP1 EXIF con GPS, APP1 XMP, APP13 IPTC y un comentario.
        $exif = "Exif\x00\x00MM\x00\x2AGPSLatitude=-54.8019;GPSLongitude=-68.3030;Model=Telefono-Privado";
        $xmp = "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta>Autor: Persona Privada</x:xmpmeta>";
        $iptc = "Photoshop 3.0\x008BIM\x04\x04Ubicacion privada";
        $comment = 'Comentario con dato privado';
        $plain = SyntheticUploads::jpeg();
        $withMetadata = "\xFF\xD8"
            ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
            ."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp
            ."\xFF\xED".pack('n', strlen($iptc) + 2).$iptc
            ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
            .substr($plain, 2);

        $avatar = $this->postAvatar(
            $token,
            $profile,
            SyntheticUploads::file('foto-telefono.jpg', 'image/jpeg', $withMetadata),
        )->assertCreated()->json('data');
        $stored = File::query()->where('public_id', $avatar['id'])->firstOrFail();
        $bytes = Storage::disk('public')->get($stored->private_path);
        $this->assertIsString($bytes);
        foreach (['Exif', 'GPSLatitude', 'Telefono-Privado', 'xmpmeta', 'Persona Privada', '8BIM', 'Ubicacion privada', $comment] as $needle) {
            $this->assertStringNotContainsString($needle, $bytes, "el avatar guardado conserva {$needle}");
        }
        $this->assertSame($plain, $bytes, 'solo se quitan los segmentos de metadatos');
        $this->assertSame(strlen($bytes), (int) $stored->size);
        $this->assertSame(hash('sha256', $bytes), $stored->checksum);
        $this->assertSame(1, $avatar['width']);

        // PNG con texto libre, eXIf y fecha: se conservan IHDR/IDAT/IEND.
        $png = SyntheticUploads::png();
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $ihdrEnd = 8 + 25;
        $pngWithText = substr($png, 0, $ihdrEnd)
            .$chunk('tEXt', "Comment\x00Direccion privada 123")
            .$chunk('eXIf', 'MM*GPS privado')
            .$chunk('tIME', "\x07\xEA\x0A\x02\x0C\x00\x00")
            .substr($png, $ihdrEnd);
        $second = $this->postAvatar(
            $token,
            $profile,
            SyntheticUploads::file('captura.png', 'image/png', $pngWithText),
        )->assertCreated()->json('data');
        $storedPng = Storage::disk('public')->get(File::query()->where('public_id', $second['id'])->firstOrFail()->private_path);
        $this->assertSame($png, $storedPng);
    }

    public function test_t22_an_image_whose_structure_cannot_be_cleaned_is_rejected_without_writes(): void
    {
        [, $token] = $this->registerAccount('avatar-unparseable@example.test');
        $profile = $this->createProfile($token, 'Guía Imagen Rota');
        // Firma y SOF válidos para getimagesize, pero un segmento APP1 con longitud que excede el archivo.
        $broken = substr(SyntheticUploads::jpeg(), 0, -2)."\xFF\xE1\x7F\xFFExif";

        $this->postAvatar($token, $profile, SyntheticUploads::file('rota.jpg', 'image/jpeg', $broken))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['image']]]);
        $this->assertSame(0, File::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_gallery_requires_active_enabled_access_and_enforces_limit_owner_and_foreign_isolation(): void
    {
        [$owner, $token] = $this->registerAccount('gallery-owner@example.test');
        [, $foreignToken] = $this->registerAccount('gallery-foreign@example.test');
        $inactive = $this->createProfile($token, 'Galería Inactiva');
        $disabled = $this->createProfile($token, 'Galería Deshabilitada');
        $enabled = $this->createProfile($token, 'Galería Habilitada');
        $this->grant($disabled, $owner, AccessGrantStatus::Active, false);
        $this->grant($enabled, $owner, AccessGrantStatus::Active, true);

        $this->postGallery(
            $token,
            $inactive,
            SyntheticUploads::file('inactive.png', 'image/png', SyntheticUploads::png()),
        )->assertForbidden()->assertJsonPath('error.code', 'subscription_required');
        $this->postGallery(
            $token,
            $disabled,
            SyntheticUploads::file('disabled.png', 'image/png', SyntheticUploads::png()),
        )->assertForbidden()->assertJsonPath('error.code', 'capability_limit_reached');
        $foreign = $this->postGallery(
            $foreignToken,
            $enabled,
            SyntheticUploads::file('foreign.png', 'image/png', SyntheticUploads::png()),
        )->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->assertStringNotContainsString('Galería Habilitada', $foreign->getContent());

        config()->set('contactur.uploads.gallery_max_items', 1);
        $first = $this->postGallery(
            $token,
            $enabled,
            SyntheticUploads::file('gallery-first.png', 'image/png', SyntheticUploads::png()),
        )->assertCreated()->json('data');
        $this->postGallery(
            $token,
            $enabled,
            SyntheticUploads::file('gallery-over-limit.jpg', 'image/jpeg', SyntheticUploads::jpeg()),
        )->assertUnprocessable()->assertJsonPath('error.code', 'gallery_limit_reached');
        $this->assertSame(1, ProfileGallery::query()->where('profile_id', $enabled->id)->count());

        $this->withToken($foreignToken)
            ->deleteJson('/api/v1/profiles/'.$enabled->public_id.'/gallery/'.$first['id'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->assertSame(1, ProfileGallery::query()->where('profile_id', $enabled->id)->count());
    }

    public function test_gallery_inserts_positions_without_unique_collisions_and_compacts_after_middle_delete(): void
    {
        [$owner, $token] = $this->registerAccount('gallery-order@example.test');
        $profile = $this->createProfile($token, 'Galería Ordenada');
        $this->grant($profile, $owner, AccessGrantStatus::Active, true);
        config()->set('contactur.uploads.gallery_max_items', 3);

        $firstResponse = $this->postGallery(
            $token,
            $profile,
            SyntheticUploads::file('position-0.png', 'image/png', SyntheticUploads::png()),
        )->assertCreated();
        $first = $firstResponse->json('data');
        $second = $this->postGallery(
            $token,
            $profile,
            SyntheticUploads::file('position-1.jpg', 'image/jpeg', SyntheticUploads::jpeg()),
        )->assertCreated()->json('data');
        $middle = $this->postGallery(
            $token,
            $profile,
            SyntheticUploads::file('inserted-middle.png', 'image/png', SyntheticUploads::png()),
            1,
        )->assertCreated()->assertJsonPath('data.position', 1)->json('data');

        $this->assertSame(
            [$first['id'], $middle['id'], $second['id']],
            ProfileGallery::query()->where('profile_id', $profile->id)->orderBy('position')->pluck('public_id')->all(),
        );
        $this->assertSame(
            [0, 1, 2],
            ProfileGallery::query()->where('profile_id', $profile->id)->orderBy('position')->pluck('position')->all(),
        );
        foreach ([$first, $second, $middle] as $item) {
            $this->assertSame(['id', 'url', 'mime_type', 'width', 'height', 'position'], array_keys($item));
            $this->assertStringContainsString('/storage/media/'.$profile->public_id.'/gallery/', $item['url']);
        }
        foreach (['private_path', 'path', 'disk', 'checksum'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $firstResponse->getContent());
        }

        $middleModel = ProfileGallery::query()->where('public_id', $middle['id'])->with('file')->firstOrFail();
        $middlePath = $middleModel->file->private_path;
        $middleFileId = $middleModel->file->id;
        $this->withToken($token)
            ->deleteJson('/api/v1/profiles/'.$profile->public_id.'/gallery/'.$middle['id'])
            ->assertNoContent();

        $this->assertSame(
            [$first['id'], $second['id']],
            ProfileGallery::query()->where('profile_id', $profile->id)->orderBy('position')->pluck('public_id')->all(),
        );
        $this->assertSame(
            [0, 1],
            ProfileGallery::query()->where('profile_id', $profile->id)->orderBy('position')->pluck('position')->all(),
        );
        $this->assertNotNull(File::query()->findOrFail($middleFileId)->storage_deleted_at);
        Storage::disk('public')->assertMissing($middlePath);
        $this->withToken($token)
            ->getJson('/api/v1/profiles/'.$profile->public_id)
            ->assertOk()
            ->assertJsonCount(2, 'data.gallery')
            ->assertJsonPath('data.gallery.0.id', $first['id'])
            ->assertJsonPath('data.gallery.0.position', 0)
            ->assertJsonPath('data.gallery.1.id', $second['id'])
            ->assertJsonPath('data.gallery.1.position', 1);
    }

    public function test_profile_or_grant_suspension_blocks_avatar_and_gallery_mutations(): void
    {
        [$owner, $token] = $this->registerAccount('media-suspended@example.test');
        $statusSuspended = $this->createProfile($token, 'Media Perfil Suspendido');
        $grantSuspended = $this->createProfile($token, 'Media Grant Suspendido');
        $statusSuspended->forceFill(['status' => ProfileStatus::Suspended])->save();
        $this->grant($grantSuspended, $owner, AccessGrantStatus::Suspended, true);

        foreach ([$statusSuspended, $grantSuspended] as $profile) {
            $this->postAvatar(
                $token,
                $profile,
                SyntheticUploads::file('blocked-avatar-'.$profile->id.'.png', 'image/png', SyntheticUploads::png()),
            )->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
            $this->postGallery(
                $token,
                $profile,
                SyntheticUploads::file('blocked-gallery-'.$profile->id.'.jpg', 'image/jpeg', SyntheticUploads::jpeg()),
            )->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
            $this->withToken($token)
                ->deleteJson('/api/v1/profiles/'.$profile->public_id.'/gallery/'.Str::ulid())
                ->assertForbidden()
                ->assertJsonPath('error.code', 'profile_suspended');
        }

        $this->assertSame(0, File::query()->count());
        $this->assertSame(0, ProfileGallery::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** @return array{User, string} */
    private function registerAccount(string $email): array
    {
        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Persona de Prueba',
            'email' => $email,
            'password' => 'Clave-segura-2026',
            'password_confirmation' => 'Clave-segura-2026',
        ])->assertCreated()->json('data');

        return [User::query()->where('email', $email)->firstOrFail(), $data['access_token']];
    }

    private function createProfile(string $token, string $displayName): Profile
    {
        $publicId = $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => ProfileCategory::Guide->value,
            'display_name' => $displayName,
        ])->assertCreated()->json('data.id');

        return Profile::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** @return TestResponse<Response> */
    private function postAvatar(string $token, Profile $profile, UploadedFile $file): TestResponse
    {
        return $this->withToken($token)->withHeader('Accept', 'application/json')->post(
            '/api/v1/profiles/'.$profile->public_id.'/avatar',
            ['image' => $file],
        );
    }

    /** @return TestResponse<Response> */
    private function postGallery(
        string $token,
        Profile $profile,
        UploadedFile $file,
        ?int $position = null,
    ): TestResponse {
        $payload = ['image' => $file];
        if ($position !== null) {
            $payload['position'] = $position;
        }

        return $this->withToken($token)->withHeader('Accept', 'application/json')->post(
            '/api/v1/profiles/'.$profile->public_id.'/gallery',
            $payload,
        );
    }

    private function grant(
        Profile $profile,
        User $actor,
        AccessGrantStatus $status,
        bool $galleryEnabled,
    ): void {
        AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => $status,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => $galleryEnabled,
            'version' => 1,
            'updated_by' => $actor->id,
            'reason' => 'Grant sintético para pruebas de media.',
        ]);
    }
}
