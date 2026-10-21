<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Events\AccessChanged;
use App\Events\AvailabilityChanged;
use App\Events\DirectoryChanged;
use App\Events\RealtimeInvalidation;
use App\Models\AccessGrant;
use App\Models\AvailabilityDay;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\Profile;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\Support\SyntheticUploads;
use Tests\TestCase;

/**
 * T16 / R10 R21: invalidaciones por websocket. Solo IDs públicos y versiones,
 * despachadas después del commit y en la cola `realtime`.
 *
 * Reloj: 2026-11-10 02:30 UTC = 2026-11-09 23:30 en Buenos Aires (hoy de negocio = 9).
 */
final class RealtimeInvalidationEventsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-09';

    private const TOMORROW = '2026-11-10';

    private const DAY_AFTER = '2026-11-11';

    private const NEXT_MONTH = '2026-12-02';

    private const EVENTS = [AvailabilityChanged::class, DirectoryChanged::class, AccessChanged::class];

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $now = CarbonImmutable::parse('2026-11-10T02:30:00Z');
        $this->travelTo($now);
        $this->clock = new FrozenClock($now);
        $this->app->instance(Clock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        SyntheticUploads::cleanup();
        parent::tearDown();
    }

    public function test_events_are_after_commit_broadcasts_on_the_realtime_queue_with_stable_names_and_channels(): void
    {
        $id = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
        $cases = [
            [new AvailabilityChanged($id, 7, ['2026-11']), 'availability.changed', ['private-directory', 'private-profile.'.$id], ['profile_id' => $id, 'version' => 7, 'months' => ['2026-11']]],
            [new DirectoryChanged($id), 'directory.changed', ['private-directory'], ['profile_id' => $id]],
            [new AccessChanged($id), 'access.changed', ['private-profile.'.$id], ['profile_id' => $id]],
        ];

        foreach ($cases as [$event, $name, $channels, $payload]) {
            $this->assertInstanceOf(RealtimeInvalidation::class, $event);
            $this->assertInstanceOf(ShouldBroadcast::class, $event);
            $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
            $this->assertSame('realtime', $event->broadcastQueue());
            $this->assertSame($name, $event->broadcastAs());
            $this->assertSame($channels, array_map(
                static fn (Channel $channel): string => $channel->name,
                $event->broadcastOn(),
            ));
            $this->assertSame($payload, $event->broadcastWith());
        }
    }

    public function test_availability_batch_that_changes_dispatches_once_with_only_ids_version_and_months(): void
    {
        Event::fake(self::EVENTS);
        [$owner, $token] = $this->registerAccount('t16-availability@example.test');
        $profile = $this->receiver($owner);
        [$ushuaia, $calafate] = $this->cities();

        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
            ['date' => self::NEXT_MONTH, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertOk()->assertJsonPath('data.version', 1);

        Event::assertDispatchedTimes(AvailabilityChanged::class, 1);
        Event::assertDispatched(AvailabilityChanged::class, function (AvailabilityChanged $event) use ($profile): bool {
            $payload = $event->broadcastWith();

            return array_keys($payload) === ['profile_id', 'version', 'months']
                && $payload['profile_id'] === $profile->public_id
                && $payload['version'] === 1
                && $payload['months'] === ['2026-11', '2026-12'];
        });
        Event::assertNotDispatched(DirectoryChanged::class);
        Event::assertNotDispatched(AccessChanged::class);
        $this->assertNoSensitiveData($profile, $owner);

        // Bloquear un día: también cambia → segunda invalidación con versión 2 y solo su mes.
        $this->putAvailability($token, $profile, 1, [
            ['date' => self::NEXT_MONTH, 'available' => false],
        ])->assertOk()->assertJsonPath('data.version', 2);
        Event::assertDispatchedTimes(AvailabilityChanged::class, 2);
        Event::assertDispatched(
            AvailabilityChanged::class,
            static fn (AvailabilityChanged $event): bool => $event->version === 2 && $event->months === ['2026-12'],
        );
    }

    public function test_noop_batch_does_not_broadcast(): void
    {
        [$owner, $token] = $this->registerAccount('t16-noop@example.test');
        $profile = $this->receiver($owner);
        [$ushuaia] = $this->cities();
        $days = [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::DAY_AFTER, 'available' => false],
        ];
        $this->putAvailability($token, $profile, 0, $days)->assertOk()->assertJsonPath('data.version', 1);

        Event::fake(self::EVENTS);
        $this->putAvailability($token, $profile, 1, $days)->assertOk()->assertJsonPath('data.version', 1);

        Event::assertNotDispatched(AvailabilityChanged::class);
    }

    public function test_rejected_or_rolled_back_batches_do_not_broadcast(): void
    {
        Event::fake(self::EVENTS);
        [$owner, $token] = $this->registerAccount('t16-rollback@example.test');
        $profile = $this->receiver($owner);
        [$ushuaia] = $this->cities();

        // 409: versión vieja.
        $this->putAvailability($token, $profile, 5, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertStatus(409)->assertJsonPath('error.code', 'stale_availability');
        // 422: un ítem inválido anula el lote completo.
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::TODAY, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertUnprocessable();
        Event::assertNotDispatched(AvailabilityChanged::class);

        // Rollback posterior a la emisión: el lote se aplicó y emitió dentro de una
        // transacción que luego falla. After-commit descarta el aviso junto con los datos.
        try {
            DB::transaction(function () use ($owner, $profile, $ushuaia): void {
                $this->app->make(AvailabilityService::class)->replace($owner, $profile, 0, [
                    ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
                ]);

                throw new RuntimeException('rollback forzado');
            });
            $this->fail('La transacción debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback forzado', $exception->getMessage());
        }

        Event::assertNotDispatched(AvailabilityChanged::class);
        $this->assertSame(0, AvailabilityDay::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, $profile->refresh()->availability_version);
    }

    public function test_admin_grant_update_invalidates_directory_and_owner_access(): void
    {
        [$owner] = $this->registerAccount('t16-grant-owner@example.test');
        $profile = $this->receiver($owner);
        [, $adminToken] = $this->registerAdmin('t16-grant-admin@example.test');
        Event::fake(self::EVENTS);

        $this->putAccess($adminToken, $profile, AccessGrantStatus::Inactive, 1)->assertOk();

        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        Event::assertDispatchedTimes(AccessChanged::class, 1);
        Event::assertDispatched(DirectoryChanged::class, fn (DirectoryChanged $event): bool => $event->broadcastWith() === ['profile_id' => $profile->public_id]);
        Event::assertDispatched(AccessChanged::class, fn (AccessChanged $event): bool => $event->broadcastWith() === ['profile_id' => $profile->public_id]);
        Event::assertNotDispatched(AvailabilityChanged::class);
        $this->assertNoSensitiveData($profile, $owner);

        // 409 de versión: la transacción no confirma y no hay avisos nuevos.
        $this->putAccess($adminToken, $profile, AccessGrantStatus::Active, 1)->assertStatus(409);
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        Event::assertDispatchedTimes(AccessChanged::class, 1);
    }

    public function test_admin_city_downgrade_also_invalidates_the_blocked_months(): void
    {
        [$owner, $token] = $this->registerAccount('t16-downgrade@example.test');
        $profile = $this->receiver($owner);
        [$ushuaia, $calafate] = $this->cities();
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
            ['date' => self::NEXT_MONTH, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertOk();
        [, $adminToken] = $this->registerAdmin('t16-downgrade-admin@example.test');
        Event::fake(self::EVENTS);

        $this->putAccess($adminToken, $profile, AccessGrantStatus::Active, 1, [
            'city_limit' => 1,
            'cities_to_keep' => [$calafate->id],
        ])->assertOk();

        Event::assertDispatchedTimes(AvailabilityChanged::class, 1);
        Event::assertDispatched(
            AvailabilityChanged::class,
            static fn (AvailabilityChanged $event): bool => $event->version === 2 && $event->months === ['2026-12'],
        );
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        Event::assertDispatchedTimes(AccessChanged::class, 1);
    }

    public function test_profile_and_account_suspension_invalidate_each_affected_profile(): void
    {
        [$owner] = $this->registerAccount('t16-suspension-owner@example.test');
        $first = $this->receiver($owner);
        $second = $this->receiver($owner, ProfileCategory::Instructor);
        [, $adminToken] = $this->registerAdmin('t16-suspension-admin@example.test');
        Event::fake(self::EVENTS);

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$first->public_id.'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Suspensión de prueba T16',
            ])->assertOk();
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        Event::assertDispatchedTimes(AccessChanged::class, 1);

        // Mismo estado: sin cambio, sin aviso.
        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$first->public_id.'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Suspensión repetida',
            ])->assertOk();
        Event::assertDispatchedTimes(AccessChanged::class, 1);

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$owner->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Suspensión de cuenta T16',
            ])->assertOk();
        Event::assertDispatchedTimes(DirectoryChanged::class, 3);
        Event::assertDispatchedTimes(AccessChanged::class, 3);
        foreach ([$first, $second] as $profile) {
            Event::assertDispatched(AccessChanged::class, fn (AccessChanged $event): bool => $event->profileId === $profile->public_id);
        }

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$owner->public_id.'/status', [
                'status' => User::STATUS_ACTIVE,
                'reason' => 'Reactivación de cuenta T16',
            ])->assertOk();
        Event::assertDispatchedTimes(AccessChanged::class, 5);
        $this->assertNoSensitiveData($first, $owner);
    }

    public function test_document_review_invalidates_the_directory_only_when_verification_changes(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$owner, $token] = $this->registerAccount('t16-document-owner@example.test');
        $profile = $this->receiver($owner);
        [, $adminToken] = $this->registerAdmin('t16-document-admin@example.test');
        $type = DocumentType::query()
            ->where('category', $profile->category->value)
            ->where('required', true)
            ->firstOrFail();
        $rejectedId = $this->uploadDocument($token, $profile, $type, 'rechazo');
        Event::fake(self::EVENTS);

        // Rechazar un pendiente no cambia la verificación.
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$rejectedId.'/review', [
            'action' => 'reject',
            'rejection_reason' => 'Documento sintético ilegible.',
        ])->assertOk();
        Event::assertNotDispatched(DirectoryChanged::class);

        // Aprobar el requerido sí: el perfil pasa a verificado y el orden D16 cambia.
        $approvedId = $this->uploadDocument($token, $profile, $type, 'aprobado');
        $this->withToken($adminToken)->postJson('/api/v1/admin/documents/'.$approvedId.'/review', [
            'action' => 'approve',
        ])->assertOk();
        Event::assertDispatchedTimes(DirectoryChanged::class, 1);
        Event::assertDispatched(DirectoryChanged::class, fn (DirectoryChanged $event): bool => $event->profileId === $profile->public_id);
        Event::assertNotDispatched(AccessChanged::class);
        $this->assertNoSensitiveData($profile, $owner);
    }

    /** Ningún payload emitido contiene email, teléfono, nombres ni descripciones. */
    private function assertNoSensitiveData(Profile $profile, User $owner): void
    {
        $profile->refresh()->loadMissing('contact');
        $forbidden = array_filter([
            $owner->email,
            $owner->name,
            $profile->display_name,
            $profile->description,
            $profile->getRelation('contact')?->contact_email,
            $profile->getRelation('contact')?->phone,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        foreach (self::EVENTS as $class) {
            foreach (Event::dispatched($class) as [$event]) {
                $this->assertInstanceOf(RealtimeInvalidation::class, $event);
                $json = json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('@', $json);
                foreach (['email', 'phone', 'name', 'description', 'document', 'contact'] as $key) {
                    $this->assertStringNotContainsString('"'.$key, $json);
                }
                foreach ($forbidden as $value) {
                    $this->assertStringNotContainsString($value, $json);
                }
                $this->assertSame([], array_diff(array_keys($event->broadcastWith()), ['profile_id', 'version', 'months']));
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return TestResponse<Response>
     */
    private function putAvailability(string $token, Profile $profile, int $version, array $days): TestResponse
    {
        return $this->withToken($token)->putJson('/api/v1/profiles/'.$profile->public_id.'/availability', [
            'expected_version' => $version,
            'days' => $days,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function putAccess(
        string $adminToken,
        Profile $profile,
        AccessGrantStatus $status,
        int $expectedVersion,
        array $overrides = [],
    ): TestResponse {
        return $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profile->public_id.'/access', array_merge([
                'status' => $status->value,
                'starts_at' => null,
                'expires_at' => null,
                'city_limit' => null,
                'monthly_contact_limit' => null,
                'gallery_enabled' => false,
                'expected_version' => $expectedVersion,
                'reason' => 'Cambio de acceso T16',
            ], $overrides));
    }

    private function uploadDocument(string $token, Profile $profile, DocumentType $type, string $seed): string
    {
        return (string) $this->withToken($token)->withHeader('Accept', 'application/json')->post(
            '/api/v1/profiles/'.$profile->public_id.'/documents',
            [
                'document_type_id' => $type->id,
                'file' => SyntheticUploads::file('t16-'.$seed.'.pdf', 'application/pdf', SyntheticUploads::pdf($seed)),
                'valid_until' => $this->clock->now()->addMonth()->toDateString(),
            ],
        )->assertCreated()->json('data.id');
    }

    /** Receptor publicado, con dos ciudades, contacto privado cargado y acceso vigente. */
    private function receiver(User $owner, ProfileCategory $category = ProfileCategory::Guide): Profile
    {
        $profile = Profile::factory()->create([
            'owner_user_id' => $owner->id,
            'category' => $category,
            'status' => ProfileStatus::Published,
            'completed_at' => $this->clock->now()->subDay(),
            'description' => 'Descripción privada de prueba T16',
        ]);
        $profile->contact()->update(['contact_email' => 'contacto-t16@example.test', 'phone' => '+54 9 11 5555-0000']);
        $profile->cities()->attach(array_map(static fn (City $city): int => $city->id, $this->cities()));
        AccessGrant::query()->create([
            'profile_id' => $profile->id,
            'status' => AccessGrantStatus::Active,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $owner->id,
            'reason' => 'Habilitación de prueba',
        ]);

        return $profile;
    }

    /** @return array<int, City> */
    private function cities(): array
    {
        return City::query()->orderBy('id')->limit(2)->get()->all();
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
}
