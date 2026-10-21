<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class AdminAccessGrantTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(CatalogSeeder::class);
        $this->clock = new FrozenClock(CarbonImmutable::now('UTC')->subMinute()->startOfSecond());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_p11_mass_assignment_is_rejected_and_normal_user_cannot_use_admin_routes(): void
    {
        [$user, $token] = $this->registerAccount('normal-user@example.test');
        $profileId = $this->createProfile($token, ProfileCategory::Guide, 'Guía segura');
        $forbiddenFields = [
            'role' => 'admin',
            'system_role' => User::ROLE_ADMIN,
            'status' => User::STATUS_SUSPENDED,
            'grant' => ['status' => 'active'],
            'access' => ['allowed' => true],
            'capabilities' => ['can_search' => true],
        ];

        $accountPatch = $this->withToken($token)->patchJson('/api/v1/me', $forbiddenFields)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        foreach (array_keys($forbiddenFields) as $field) {
            $accountPatch->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }

        $profilePatch = $this->withToken($token)->patchJson('/api/v1/profiles/'.$profileId, $forbiddenFields)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        foreach (array_keys($forbiddenFields) as $field) {
            $profilePatch->assertJsonStructure(['error' => ['fields' => [$field]]]);
        }

        $this->withToken($token)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->withToken($token)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden();

        $user->fill([
            'system_role' => User::ROLE_ADMIN,
            'status' => User::STATUS_SUSPENDED,
            'grant' => ['status' => 'active'],
        ]);
        $this->assertSame(User::ROLE_USER, $user->system_role);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertArrayNotHasKey('grant', $user->getAttributes());

        $this->assertSame(User::ROLE_USER, $user->refresh()->system_role);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame(ProfileStatus::Draft, Profile::query()->where('public_id', $profileId)->value('status'));
        $this->assertSame(0, AccessGrant::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_p11_admin_mutation_versions_and_audits_only_safe_changes_with_request_id(): void
    {
        [, $ownerToken] = $this->registerAccount('grant-owner@example.test');
        $profileId = $this->createProfile($ownerToken, ProfileCategory::TourismCompany, 'Empresa habilitada');
        [$admin, $adminToken] = $this->registerAccount('access-admin@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();

        $this->withHeader('X-Request-ID', 'grant-write-001')
            ->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload([
                'status' => 'active',
                'starts_at' => $this->clock->now()->subSecond()->toISOString(),
                'expires_at' => null,
                'city_limit' => null,
                'monthly_contact_limit' => 10,
                'gallery_enabled' => true,
                'expected_version' => 0,
                'reason' => 'Habilitación manual aprobada',
            ]))
            ->assertOk()
            ->assertJsonPath('data.effective_state', 'active')
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.grant.version', 1)
            ->assertJsonPath('data.capabilities.can_send_contact_requests', true)
            ->assertJsonPath('data.capabilities.can_publish_availability', false);

        $grant = AccessGrant::query()->firstOrFail();
        $audit = AuditLog::query()->with('actor')->firstOrFail();
        $this->assertSame(1, $grant->version);
        $this->assertSame($admin->id, $grant->updated_by);
        $this->assertSame('Habilitación manual aprobada', $grant->reason);
        $this->assertSame('grant-write-001', $audit->request_id);
        $this->assertSame('access_grant.replaced', $audit->action);
        $this->assertSame($profileId, $audit->target_id);
        $this->assertNull($audit->safe_changes['before']);
        $this->assertSame(1, $audit->safe_changes['after']['version']);
        $encodedAudit = json_encode($audit->safe_changes, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('grant-owner@example.test', $encodedAudit);
        $this->assertStringNotContainsString('contact_email', $encodedAudit);
        $this->assertStringNotContainsString('phone', $encodedAudit);
        $this->assertStringNotContainsString('token', $encodedAudit);

        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profileId.'/access')
            ->assertOk()
            ->assertJsonPath('data.grant.version', 1)
            ->assertJsonPath('data.effective_state', 'active');
        $this->withToken($ownerToken)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.profiles.0.access_state', 'active');
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/audit-logs?action=access_grant.replaced&target_type=profile')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor_user_id', $admin->public_id)
            ->assertJsonPath('data.0.request_id', 'grant-write-001')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_p11_two_writers_with_same_expected_version_leave_one_winner(): void
    {
        [, $ownerToken] = $this->registerAccount('concurrent-owner@example.test');
        $profileId = $this->createProfile($ownerToken, ProfileCategory::Guide, 'Guía concurrente');
        [$admin, $adminToken] = $this->registerAccount('concurrent-admin@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();
        $firstPayload = $this->grantPayload(['reason' => 'Primera operación concurrente']);
        $secondPayload = $this->grantPayload(['reason' => 'Segunda operación concurrente']);

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $firstPayload)
            ->assertOk()
            ->assertJsonPath('data.grant.version', 1);
        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $secondPayload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'stale_access_grant');

        $this->assertSame(1, AccessGrant::query()->whereHas('profile', fn ($query) => $query->where('public_id', $profileId))->value('version'));
        $this->assertSame(1, AuditLog::query()->where('target_id', $profileId)->count());
        $this->assertSame('Primera operación concurrente', AccessGrant::query()->firstOrFail()->reason);
    }

    public function test_p11_city_downgrade_requires_explicit_subset_and_owner_replacements_obey_configured_limit_even_expired(): void
    {
        [, $ownerToken] = $this->registerAccount('cities-owner@example.test');
        $profileId = $this->createProfile($ownerToken, ProfileCategory::Guide, 'Guía de ciudades');
        [$admin, $adminToken] = $this->registerAccount('cities-admin@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();
        $cities = City::query()->orderBy('id')->take(3)->get();
        $cityIds = $cities->pluck('id')->all();

        $this->withToken($ownerToken)
            ->putJson('/api/v1/profiles/'.$profileId.'/cities', ['city_ids' => $cityIds])
            ->assertOk();
        $this->withToken($ownerToken)
            ->patchJson('/api/v1/profiles/'.$profileId, ['primary_city_id' => $cityIds[0]])
            ->assertOk();
        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload([
                'city_limit' => 3,
                'expected_version' => 0,
                'reason' => 'Límite inicial de ciudades',
            ]))->assertOk()->assertJsonPath('data.grant.version', 1);

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload([
                'city_limit' => 1,
                'expected_version' => 1,
                'reason' => 'Downgrade sin selección',
            ]))->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['cities_to_keep']]]);
        $this->assertSame(3, $this->profile($profileId)->cities()->count());

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload([
                'city_limit' => 1,
                'expected_version' => 1,
                'cities_to_keep' => [$cityIds[1]],
                'reason' => 'Downgrade excluyendo principal',
            ]))->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['cities_to_keep']]]);
        $this->assertSame(3, $this->profile($profileId)->cities()->count());

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload([
                'city_limit' => 1,
                'expected_version' => 1,
                'cities_to_keep' => [$cityIds[0]],
                'reason' => 'Downgrade explícito válido',
            ]))->assertOk()->assertJsonPath('data.grant.version', 2);
        $profile = $this->profile($profileId);
        $this->assertSame([$cityIds[0]], $profile->cities()->pluck('cities.id')->all());
        $this->assertSame($cityIds[0], $profile->primary_city_id);

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload([
                'status' => 'active',
                'starts_at' => $this->clock->now()->subDay()->toISOString(),
                'expires_at' => $this->clock->now()->toISOString(),
                'city_limit' => 1,
                'expected_version' => 2,
                'reason' => 'Grant vencido con capacidad definida',
            ]))->assertOk()->assertJsonPath('data.effective_state', 'expired');
        $this->withToken($ownerToken)
            ->putJson('/api/v1/profiles/'.$profileId.'/cities', ['city_ids' => [$cityIds[0], $cityIds[1]]])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['city_ids']]]);
        $this->withToken($ownerToken)
            ->putJson('/api/v1/profiles/'.$profileId.'/cities', ['city_ids' => [$cityIds[0]]])
            ->assertOk();

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profileId.'/access', $this->grantPayload([
                'city_limit' => 0,
                'expected_version' => 3,
                'cities_to_keep' => [],
                'reason' => 'Capacidad de ciudades bloqueada',
            ]))->assertOk()->assertJsonPath('data.grant.city_limit', 0);
        $profile = $this->profile($profileId);
        $this->assertSame(0, $profile->cities()->count());
        $this->assertNull($profile->primary_city_id);
        $this->assertSame(4, AccessGrant::query()->where('profile_id', $profile->id)->value('version'));
    }

    /** @param array<string, mixed> $overrides */
    private function grantPayload(array $overrides = []): array
    {
        return array_merge([
            'status' => 'inactive',
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'expected_version' => 0,
            'reason' => 'Actualización administrativa de prueba',
        ], $overrides);
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

    private function createProfile(string $token, ProfileCategory $category, string $displayName): string
    {
        return $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => $category->value,
            'display_name' => $displayName,
        ])->assertCreated()->json('data.id');
    }

    private function profile(string $publicId): Profile
    {
        return Profile::query()->where('public_id', $publicId)->firstOrFail();
    }
}
