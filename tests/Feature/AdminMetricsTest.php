<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class AdminMetricsTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->startOfSecond());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_t11_metrics_are_forbidden_for_a_normal_account_and_for_a_suspended_admin(): void
    {
        [, $userToken] = $this->registerAccount('metrics-intruder@example.test');
        $this->withToken($userToken)
            ->getJson('/api/v1/admin/metrics')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        [$admin, $adminToken] = $this->registerAdmin('metrics-suspended-admin@example.test');
        $admin->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/metrics')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_t11_metrics_count_real_rows_per_status_category_and_effective_access_state(): void
    {
        [$admin, $adminToken] = $this->registerAdmin('metrics-admin@example.test');
        $activeOwner = User::factory()->create(['name' => 'Ana Activa']);
        $inactiveOwner = User::factory()->create(['name' => 'Bruno Sin Grant']);
        $expiredOwner = User::factory()->create(['name' => 'Carla Vencida']);
        $suspendedProfileOwner = User::factory()->create(['name' => 'Diego Perfil Suspendido']);
        $suspendedAccountOwner = User::factory()->suspended()->create(['name' => 'Elena Cuenta Suspendida']);

        $activeGuide = $this->profileFor($activeOwner, ProfileCategory::Guide, ProfileStatus::Published);
        $this->grant($activeGuide, AccessGrantStatus::Active);
        $inactiveCompany = $this->profileFor($inactiveOwner, ProfileCategory::TourismCompany, ProfileStatus::Published);
        $expiredDriver = $this->profileFor($expiredOwner, ProfileCategory::Driver, ProfileStatus::Draft);
        $this->grant($expiredDriver, AccessGrantStatus::Active, $this->clock->now()->subDays(3), $this->clock->now()->subDay());
        $suspendedInstructor = $this->profileFor($suspendedProfileOwner, ProfileCategory::Instructor, ProfileStatus::Suspended);
        $this->grant($suspendedInstructor, AccessGrantStatus::Active);
        $suspendedOwnerTransport = $this->profileFor($suspendedAccountOwner, ProfileCategory::TransportCompany, ProfileStatus::Published);
        $this->grant($suspendedOwnerTransport, AccessGrantStatus::Active);

        $this->document($activeGuide, $activeOwner, ProfileDocument::STATUS_PENDING);
        $this->document($activeGuide, $activeOwner, ProfileDocument::STATUS_PENDING, $this->extraGuideType());
        $this->document($expiredDriver, $expiredOwner, ProfileDocument::STATUS_APPROVED, reviewer: $admin);
        $this->document($inactiveCompany, $inactiveOwner, ProfileDocument::STATUS_REJECTED, reviewer: $admin);

        $response = $this->withToken($adminToken)->getJson('/api/v1/admin/metrics')->assertOk();

        // 5 activas: el admin más los cuatro titulares activos; 1 suspendida.
        $this->assertSame(
            [User::STATUS_ACTIVE => 5, User::STATUS_SUSPENDED => 1],
            $response->json('data.users'),
        );
        $this->assertSame(6, User::query()->count());

        $this->assertSame([
            ProfileCategory::TourismCompany->value => 1,
            ProfileCategory::Guide->value => 1,
            ProfileCategory::Driver->value => 1,
            ProfileCategory::Instructor->value => 1,
            ProfileCategory::TransportCompany->value => 1,
        ], $response->json('data.profiles_by_category'));

        // Misma precedencia cuenta → perfil → grant que AccessService: el transporte
        // cuenta como suspended por su titular y el instructor por su propio estado.
        $this->assertSame([
            'inactive' => 1,
            'active' => 1,
            'expired' => 1,
            'suspended' => 2,
        ], $response->json('data.profiles_by_access_state'));

        // Contraste contra el listado del lote 1, que sí evalúa con AccessService:
        // cada contador debe coincidir con los perfiles que devuelve ese filtro.
        foreach ([
            'active' => [$activeGuide->public_id],
            'inactive' => [$inactiveCompany->public_id],
            'expired' => [$expiredDriver->public_id],
            'suspended' => [$suspendedOwnerTransport->public_id, $suspendedInstructor->public_id],
        ] as $state => $expectedIds) {
            $listed = $this->withToken($adminToken)
                ->getJson('/api/v1/admin/profiles?access_state='.$state)
                ->assertOk()
                ->json('data.*.profile.id');
            $this->assertSame($expectedIds, $listed);
            $this->assertCount(
                (int) $response->json('data.profiles_by_access_state.'.$state),
                (array) $listed,
            );
        }

        $this->assertSame(2, $response->json('data.documents_pending'));
        $this->assertSame(4, ProfileDocument::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_t11_empty_sources_report_real_zero_counts_and_the_shape_matches_the_contract(): void
    {
        [, $adminToken] = $this->registerAdmin('metrics-empty-admin@example.test');
        // contact_requests existe desde T17 y support_inquiries desde T19: ambas cuentan filas reales.
        $this->assertTrue(Schema::hasTable('contact_requests'));
        $this->assertTrue(Schema::hasTable('support_inquiries'));

        $response = $this->withToken($adminToken)->getJson('/api/v1/admin/metrics')->assertOk();

        // Las claves del enum viajan en cero para que la UI no invente categorías.
        $this->assertSame(
            [User::STATUS_ACTIVE => 1, User::STATUS_SUSPENDED => 0],
            $response->json('data.users'),
        );
        $this->assertSame([
            ProfileCategory::TourismCompany->value => 0,
            ProfileCategory::Guide->value => 0,
            ProfileCategory::Driver->value => 0,
            ProfileCategory::Instructor->value => 0,
            ProfileCategory::TransportCompany->value => 0,
        ], $response->json('data.profiles_by_category'));
        $this->assertSame([
            'inactive' => 0,
            'active' => 0,
            'expired' => 0,
            'suspended' => 0,
        ], $response->json('data.profiles_by_access_state'));
        $this->assertSame(0, $response->json('data.documents_pending'));

        // Tabla real sin filas: las cinco claves de ContactRequestStatus en 0.
        $this->assertSame([
            'pending' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'expired' => 0,
            'cancelled' => 0,
        ], $response->json('data.contact_requests_by_status'));
        // Tabla real sin filas: 0 contado, ya no null.
        $this->assertSame(0, $response->json('data.support_inquiries_pending'));

        $this->assertContractShape($response->getContent());
        $this->assertStringContainsString('"support_inquiries_pending":0', (string) $response->getContent());
    }

    public function test_t11_contact_requests_and_pending_support_inquiries_count_real_rows_within_the_range(): void
    {
        [, $adminToken] = $this->registerAdmin('metrics-future-admin@example.test');
        $company = $this->profileFor(User::factory()->create(), ProfileCategory::TourismCompany, ProfileStatus::Published);

        $inside = $this->clock->now()->setTimezone('America/Argentina/Buenos_Aires')->subDays(10);
        $outside = $this->clock->now()->setTimezone('America/Argentina/Buenos_Aires')->subDays(40);
        foreach ([
            ['pending', $inside],
            ['pending', $inside],
            ['accepted', $inside],
            ['rejected', $inside],
            ['expired', $outside],
        ] as [$status, $createdAt]) {
            // Un destino por fila: el índice único parcial admite una sola pending por par.
            $recipient = $this->profileFor(User::factory()->create(), ProfileCategory::Guide, ProfileStatus::Published);
            DB::table('contact_requests')->insert([
                'public_id' => (string) Str::ulid(),
                'sender_profile_id' => $company->id,
                'recipient_profile_id' => $recipient->id,
                'service_description' => 'Solicitud de prueba',
                'status' => $status,
                'availability_status' => 'unspecified',
                'expires_at' => $createdAt->addDays(7),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
        foreach ([
            ['pending', $inside],
            ['pending', $inside],
            ['pending', $outside],
            ['answered', $inside],
            ['discarded', $inside],
        ] as [$status, $createdAt]) {
            DB::table('support_inquiries')->insert([
                'public_id' => (string) Str::ulid(),
                'name' => 'Persona Ficticia',
                'email' => 'consulta@example.test',
                'category' => null,
                'message' => 'Consulta de prueba para métricas.',
                'status' => $status,
                'ip_hash' => hash('sha256', 'metrics'),
                'created_at' => $createdAt,
            ]);
        }

        // Solo `pending` cuenta; answered/discarded no.
        $full = $this->withToken($adminToken)->getJson('/api/v1/admin/metrics')->assertOk();
        $this->assertSame(
            ['pending' => 2, 'accepted' => 1, 'rejected' => 1, 'expired' => 1, 'cancelled' => 0],
            $full->json('data.contact_requests_by_status'),
        );
        $this->assertSame(3, $full->json('data.support_inquiries_pending'));
        $this->assertContractShape($full->getContent());

        $ranged = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/metrics?date_from='.$inside->subDay()->toDateString()
                .'&date_to='.$inside->addDay()->toDateString())
            ->assertOk();
        $this->assertSame(
            ['pending' => 2, 'accepted' => 1, 'rejected' => 1, 'expired' => 0, 'cancelled' => 0],
            $ranged->json('data.contact_requests_by_status'),
        );
        $this->assertSame(2, $ranged->json('data.support_inquiries_pending'));
    }

    public function test_t11_the_date_range_filters_by_creation_date_and_rejects_an_inverted_range(): void
    {
        [, $adminToken] = $this->registerAdmin('metrics-range-admin@example.test');
        $local = $this->clock->now()->setTimezone('America/Argentina/Buenos_Aires');
        $from = $local->subDays(15)->startOfDay();
        $to = $local->subDays(5)->startOfDay();

        // El admin se crea "ahora", después de date_to, así que queda fuera del rango.
        $inside = User::factory()->create(['name' => 'Dentro del rango']);
        $inside->forceFill(['created_at' => $from->addDays(3)])->save();
        // Último instante del día de date_to: el extremo es inclusivo.
        $edge = User::factory()->create(['name' => 'Borde superior']);
        $edge->forceFill(['created_at' => $to->addDay()->subSecond()])->save();
        $before = User::factory()->suspended()->create(['name' => 'Antes del rango']);
        $before->forceFill(['created_at' => $from->subSecond()])->save();

        $insideProfile = $this->profileFor($inside, ProfileCategory::Guide, ProfileStatus::Published);
        $insideProfile->forceFill(['created_at' => $from->addDays(3)])->save();
        $outsideProfile = $this->profileFor($before, ProfileCategory::Driver, ProfileStatus::Published);
        $outsideProfile->forceFill(['created_at' => $from->subDays(2)])->save();

        $insideDocument = $this->document($insideProfile, $inside, ProfileDocument::STATUS_PENDING);
        $insideDocument->forceFill(['created_at' => $from->addDays(3)])->save();
        $outsideDocument = $this->document($outsideProfile, $before, ProfileDocument::STATUS_PENDING);
        $outsideDocument->forceFill(['created_at' => $from->subDays(2)])->save();

        $unfiltered = $this->withToken($adminToken)->getJson('/api/v1/admin/metrics')->assertOk();
        $this->assertSame(
            [User::STATUS_ACTIVE => 3, User::STATUS_SUSPENDED => 1],
            $unfiltered->json('data.users'),
        );
        $this->assertSame(2, $unfiltered->json('data.documents_pending'));

        $ranged = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/metrics?date_from='.$from->toDateString().'&date_to='.$to->toDateString())
            ->assertOk();
        $this->assertSame(
            [User::STATUS_ACTIVE => 2, User::STATUS_SUSPENDED => 0],
            $ranged->json('data.users'),
        );
        $this->assertSame([
            ProfileCategory::TourismCompany->value => 0,
            ProfileCategory::Guide->value => 1,
            ProfileCategory::Driver->value => 0,
            ProfileCategory::Instructor->value => 0,
            ProfileCategory::TransportCompany->value => 0,
        ], $ranged->json('data.profiles_by_category'));
        // El perfil de dentro del rango no tiene grant: inactive; el de afuera no cuenta.
        $this->assertSame([
            'inactive' => 1,
            'active' => 0,
            'expired' => 0,
            'suspended' => 0,
        ], $ranged->json('data.profiles_by_access_state'));
        $this->assertSame(1, $ranged->json('data.documents_pending'));

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/metrics?date_from='.$to->toDateString().'&date_to='.$from->toDateString())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['date_to']]]);
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/metrics?date_from=2026-13-45&date_to=ayer&per_page=5')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['date_from', 'date_to', 'per_page']]]);
    }

    private function assertContractShape(string|false $content): void
    {
        $payload = json_decode((string) $content, false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertSame(['data'], array_keys((array) $payload));
        $metrics = $payload->data;
        $this->assertInstanceOf(stdClass::class, $metrics);
        $keys = array_keys((array) $metrics);
        sort($keys);
        $this->assertSame([
            'contact_requests_by_status',
            'documents_pending',
            'profiles_by_access_state',
            'profiles_by_category',
            'support_inquiries_pending',
            'users',
        ], $keys);

        foreach (['users', 'profiles_by_category', 'profiles_by_access_state', 'contact_requests_by_status'] as $map) {
            $this->assertInstanceOf(stdClass::class, $metrics->{$map});
            foreach ((array) $metrics->{$map} as $value) {
                $this->assertIsInt($value);
                $this->assertGreaterThanOrEqual(0, $value);
            }
        }
        $this->assertIsInt($metrics->documents_pending);
        $this->assertGreaterThanOrEqual(0, $metrics->documents_pending);
        // T19: la fuente existe, así que siempre es un entero >= 0.
        $this->assertIsInt($metrics->support_inquiries_pending);
        $this->assertGreaterThanOrEqual(0, $metrics->support_inquiries_pending);
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

    /** @return array{User, string} */
    private function registerAdmin(string $email): array
    {
        [$user, $token] = $this->registerAccount($email);
        $user->forceFill(['system_role' => User::ROLE_ADMIN])->save();

        return [$user, $token];
    }

    private function profileFor(User $owner, ProfileCategory $category, ProfileStatus $status): Profile
    {
        return Profile::factory()->create([
            'owner_user_id' => $owner->id,
            'category' => $category,
            'status' => $status,
            'completed_at' => $status === ProfileStatus::Published ? $this->clock->now()->subDay() : null,
        ]);
    }

    private function document(
        Profile $profile,
        User $uploader,
        string $status,
        ?DocumentType $type = null,
        ?User $reviewer = null,
    ): ProfileDocument {
        $file = File::factory()->create([
            'owner_profile_id' => $profile->id,
            'uploader_user_id' => $uploader->id,
        ]);
        $reviewed = $status !== ProfileDocument::STATUS_PENDING;

        return ProfileDocument::query()->create([
            'profile_id' => $profile->id,
            'document_type_id' => ($type ?? $this->typeFor($profile->category))->id,
            'file_id' => $file->id,
            'revision' => 1,
            'status' => $status,
            'valid_until' => $this->clock->now()->addYear()->toDateString(),
            'reviewed_by' => $reviewed ? $reviewer?->id : null,
            'reviewed_at' => $reviewed ? $this->clock->now() : null,
            'rejection_reason' => $status === ProfileDocument::STATUS_REJECTED ? 'Documento ilegible.' : null,
        ]);
    }

    private function typeFor(ProfileCategory $category): DocumentType
    {
        return DocumentType::query()->where('category', $category->value)->orderBy('id')->firstOrFail();
    }

    private function extraGuideType(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['category' => ProfileCategory::Guide->value, 'code' => 'guide_first_aid'],
            ['label' => 'Curso de primeros auxilios', 'requires_expiration' => false, 'required' => false],
        );
    }

    private function grant(
        Profile $profile,
        AccessGrantStatus $status,
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $expiresAt = null,
    ): AccessGrant {
        return AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $profile->owner_user_id,
            'reason' => 'Habilitación de prueba',
        ]);
    }
}
