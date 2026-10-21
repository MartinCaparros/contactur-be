<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Profile;
use App\Models\ProfileContact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\TestCase;

final class AdminBackofficeTest extends TestCase
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

    public function test_t11_normal_user_is_forbidden_on_every_new_backoffice_route(): void
    {
        [, $token] = $this->registerAccount('backoffice-intruder@example.test');
        $target = User::factory()->create();
        $profile = Profile::factory()->create();

        $blocked = [
            $this->withToken($token)->getJson('/api/v1/admin/users'),
            $this->withToken($token)->getJson('/api/v1/admin/profiles'),
            $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Intento de escalada de privilegios',
            ]),
            $this->withToken($token)->patchJson('/api/v1/admin/profiles/'.$profile->public_id.'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Intento de escalada de privilegios',
            ]),
        ];

        foreach ($blocked as $response) {
            $response
                ->assertForbidden()
                ->assertJsonPath('error.code', 'forbidden');
        }

        $this->assertSame(User::STATUS_ACTIVE, $target->refresh()->status);
        $this->assertSame(ProfileStatus::Draft, $profile->refresh()->status);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_t11_suspended_admin_is_stopped_by_account_state_before_the_admin_gate(): void
    {
        [$admin, $token] = $this->registerAccount('suspended-admin@example.test');
        $admin->forceFill([
            'system_role' => User::ROLE_ADMIN,
            'status' => User::STATUS_SUSPENDED,
        ])->save();
        $target = User::factory()->create();
        $profile = Profile::factory()->create();

        $blocked = [
            $this->withToken($token)->getJson('/api/v1/admin/users'),
            $this->withToken($token)->getJson('/api/v1/admin/profiles'),
            $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Administrador suspendido intentando operar',
            ]),
            $this->withToken($token)->patchJson('/api/v1/admin/profiles/'.$profile->public_id.'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Administrador suspendido intentando operar',
            ]),
        ];

        foreach ($blocked as $response) {
            $response
                ->assertForbidden()
                ->assertJsonPath('error.code', 'account_suspended');
        }

        $this->assertSame(User::STATUS_ACTIVE, $target->refresh()->status);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_t11_user_listing_filters_searches_paginates_and_never_leaks_secrets(): void
    {
        [$admin, $adminToken] = $this->registerAdmin('users-list-admin@example.test');
        $ana = User::factory()->create(['name' => 'Ana Gómez', 'email' => 'ana.gomez@example.test']);
        $bruno = User::factory()->create([
            'name' => 'Bruno Pérez',
            'email' => 'bruno.perez@example.test',
            'pending_email' => 'bruno.nuevo@example.test',
        ]);
        $carla = User::factory()->suspended()->create([
            'name' => 'Carla Ruiz',
            'email' => 'carla.ruiz@example.test',
        ]);
        Profile::factory()->count(2)->create(['owner_user_id' => $ana->id]);

        $all = $this->withToken($adminToken)->getJson('/api/v1/admin/users')->assertOk();
        $this->assertSame(
            [$carla->public_id, $bruno->public_id, $ana->public_id, $admin->public_id],
            $this->ids($all, 'id'),
        );
        $all->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.next_cursor', null);

        $anaPayload = collect($all->json('data'))->firstWhere('id', $ana->public_id);
        $this->assertIsArray($anaPayload);
        $this->assertSame(2, $anaPayload['profile_count']);
        $this->assertSame(User::STATUS_ACTIVE, $anaPayload['status']);
        $this->assertSame(User::ROLE_USER, $anaPayload['system_role']);
        $this->assertArrayHasKey('created_at', $anaPayload);
        $this->assertSame(
            ['created_at', 'email', 'email_verified_at', 'id', 'name', 'profile_count', 'status', 'system_role'],
            $this->sortedKeys($anaPayload),
        );

        $body = (string) $all->getContent();
        $storedHash = (string) User::query()->whereKey($bruno->id)->value('password');
        $storedToken = (string) User::query()->whereKey($bruno->id)->value('remember_token');
        foreach (['password', 'remember_token', 'pending_email', 'bruno.nuevo@example.test', $storedHash, $storedToken] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $suspended = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/users?status='.User::STATUS_SUSPENDED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
        $this->assertSame([$carla->public_id], $this->ids($suspended, 'id'));
        $activeIds = $this->ids(
            $this->withToken($adminToken)->getJson('/api/v1/admin/users?status='.User::STATUS_ACTIVE)->assertOk(),
            'id',
        );
        $this->assertSame([$bruno->public_id, $ana->public_id, $admin->public_id], $activeIds);

        $byName = $this->withToken($adminToken)->getJson('/api/v1/admin/users?q=G%C3%93MEZ')->assertOk();
        $this->assertSame([$ana->public_id], $this->ids($byName, 'id'));
        $byEmail = $this->withToken($adminToken)->getJson('/api/v1/admin/users?q=bruno.perez%40example')->assertOk();
        $this->assertSame([$bruno->public_id], $this->ids($byEmail, 'id'));

        foreach (['%25', '_', '%25a%25'] as $wildcard) {
            $this->withToken($adminToken)
                ->getJson('/api/v1/admin/users?q='.$wildcard)
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }

        $firstPage = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/users?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.next_cursor', (string) $bruno->id);
        $this->assertSame([$carla->public_id, $bruno->public_id], $this->ids($firstPage, 'id'));

        $secondPage = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/users?per_page=2&cursor='.$bruno->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.next_cursor', null);
        $this->assertSame([$ana->public_id, $admin->public_id], $this->ids($secondPage, 'id'));

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/users?per_page=0&status=unknown&q=')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['per_page', 'status', 'q']]]);
    }

    public function test_t11_profile_listing_combines_filters_and_reports_the_real_evaluation(): void
    {
        [, $adminToken] = $this->registerAdmin('profiles-list-admin@example.test');
        $activeGuide = $this->ownedProfile('Guía del Sur', 'guia-del-sur-activo', ProfileCategory::Guide, ProfileStatus::Published);
        $this->grant($activeGuide, AccessGrantStatus::Active);
        $inactiveCompany = $this->ownedProfile('Turismo Andino', 'turismo-andino-inactivo', ProfileCategory::TourismCompany, ProfileStatus::Published);
        $suspendedOwnerGuide = $this->ownedProfile('Guía del Norte', 'guia-del-norte-cuenta', ProfileCategory::Guide, ProfileStatus::Published);
        $this->grant($suspendedOwnerGuide, AccessGrantStatus::Active);
        $suspendedOwnerGuide->owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $suspendedGuide = $this->ownedProfile('Guía del Este', 'guia-del-este-perfil', ProfileCategory::Guide, ProfileStatus::Suspended);
        $this->grant($suspendedGuide, AccessGrantStatus::Active);
        $expiredDriver = $this->ownedProfile('Transporte Lento', 'transporte-lento-vencido', ProfileCategory::Driver, ProfileStatus::Draft);
        $this->grant($expiredDriver, AccessGrantStatus::Active, $this->clock->now()->subDays(2), $this->clock->now()->subDay());

        $this->assertSame(
            [
                $expiredDriver->public_id,
                $suspendedGuide->public_id,
                $suspendedOwnerGuide->public_id,
                $inactiveCompany->public_id,
                $activeGuide->public_id,
            ],
            $this->ids($this->withToken($adminToken)->getJson('/api/v1/admin/profiles')->assertOk(), 'profile.id'),
        );

        $this->assertSame(
            [$suspendedGuide->public_id, $suspendedOwnerGuide->public_id, $activeGuide->public_id],
            $this->ids(
                $this->withToken($adminToken)
                    ->getJson('/api/v1/admin/profiles?category='.ProfileCategory::Guide->value)
                    ->assertOk()
                    ->assertJsonPath('meta.total', 3),
                'profile.id',
            ),
        );
        $this->assertSame(
            [$suspendedOwnerGuide->public_id, $activeGuide->public_id],
            $this->ids(
                $this->withToken($adminToken)
                    ->getJson('/api/v1/admin/profiles?category='.ProfileCategory::Guide->value.'&status='.ProfileStatus::Published->value)
                    ->assertOk(),
                'profile.id',
            ),
        );
        $this->assertSame(
            [$activeGuide->public_id],
            $this->ids(
                $this->withToken($adminToken)
                    ->getJson('/api/v1/admin/profiles?category='.ProfileCategory::Guide->value
                        .'&status='.ProfileStatus::Published->value.'&access_state=active')
                    ->assertOk()
                    ->assertJsonPath('meta.total', 1),
                'profile.id',
            ),
        );
        $this->assertSame(
            [$suspendedGuide->public_id, $suspendedOwnerGuide->public_id],
            $this->ids(
                $this->withToken($adminToken)->getJson('/api/v1/admin/profiles?access_state=suspended')->assertOk(),
                'profile.id',
            ),
        );
        $this->assertSame(
            [$inactiveCompany->public_id],
            $this->ids(
                $this->withToken($adminToken)->getJson('/api/v1/admin/profiles?access_state=inactive')->assertOk(),
                'profile.id',
            ),
        );
        $this->assertSame(
            [$expiredDriver->public_id],
            $this->ids(
                $this->withToken($adminToken)->getJson('/api/v1/admin/profiles?access_state=expired')->assertOk(),
                'profile.id',
            ),
        );
        $this->assertSame(
            [],
            $this->ids(
                $this->withToken($adminToken)
                    ->getJson('/api/v1/admin/profiles?category='.ProfileCategory::Instructor->value.'&access_state=active')
                    ->assertOk(),
                'profile.id',
            ),
        );

        // La denial_code y las capabilities no existen en el filtro SQL: solo pueden
        // venir de AccessService, así que su presencia prueba la evaluación real.
        $accountSuspended = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/profiles?q=guia-del-norte')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.profile.id', $suspendedOwnerGuide->public_id)
            ->assertJsonPath('data.0.access.profile_id', $suspendedOwnerGuide->public_id)
            ->assertJsonPath('data.0.access.effective_state', 'suspended')
            ->assertJsonPath('data.0.access.allowed', false)
            ->assertJsonPath('data.0.access.denial_code', 'account_suspended')
            ->assertJsonPath('data.0.access.capabilities.can_view_history', false)
            ->assertJsonPath('data.0.access.grant.status', AccessGrantStatus::Active->value)
            ->assertJsonPath('data.0.owner.status', User::STATUS_SUSPENDED)
            ->assertJsonPath('data.0.owner.profile_count', 1);
        $this->assertSame(
            ['access', 'owner', 'profile'],
            $this->sortedKeys($accountSuspended->json('data.0')),
        );
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/profiles?q=DEL%20ESTE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.profile.id', $suspendedGuide->public_id)
            ->assertJsonPath('data.0.profile.status', ProfileStatus::Suspended->value)
            ->assertJsonPath('data.0.access.denial_code', 'profile_suspended');
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/profiles?q=_')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $page = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/profiles?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.next_cursor', (string) $suspendedGuide->id);
        $this->assertSame(
            [$expiredDriver->public_id, $suspendedGuide->public_id],
            $this->ids($page, 'profile.id'),
        );
        $this->assertSame(
            [$suspendedOwnerGuide->public_id, $inactiveCompany->public_id],
            $this->ids(
                $this->withToken($adminToken)
                    ->getJson('/api/v1/admin/profiles?per_page=2&cursor='.$suspendedGuide->id)
                    ->assertOk(),
                'profile.id',
            ),
        );
    }

    public function test_t11_profile_listing_keeps_a_constant_query_count_per_page_size(): void
    {
        [, $adminToken] = $this->registerAdmin('profiles-nplus1-admin@example.test');
        for ($index = 1; $index <= 6; $index++) {
            $profile = $this->ownedProfile(
                'Guía número '.$index,
                'guia-numero-'.$index,
                ProfileCategory::Guide,
                ProfileStatus::Published,
            );
            $this->grant($profile, AccessGrantStatus::Active);
        }

        // Se usan solo perfiles guide porque la cuota mensual de contacto que
        // AccessService evalúa para tourism_company consulta aparte; lo que se mide
        // acá es que relaciones, profile_count, verified y grant no escalen por ítem.
        $single = $this->countQueries($adminToken, '/api/v1/admin/profiles?per_page=1');
        $full = $this->countQueries($adminToken, '/api/v1/admin/profiles?per_page=6');

        $this->assertGreaterThan(10, $single);
        $this->assertSame($single, $full);
    }

    public function test_t11_user_suspension_audits_safe_changes_and_narrows_the_account_to_reads(): void
    {
        [$admin, $adminToken] = $this->registerAdmin('suspend-admin@example.test');
        [$target, $targetToken] = $this->registerAccount('suspend-target@example.test');
        $profile = Profile::factory()->create([
            'owner_user_id' => $target->id,
            'status' => ProfileStatus::Published,
        ]);
        ProfileContact::query()->updateOrCreate(['profile_id' => $profile->id], [
            'contact_email' => 'contacto-publico@example.test',
            'phone' => '+542944000111',
        ]);
        $this->grant($profile, AccessGrantStatus::Active);

        $this->withHeader('X-Request-ID', 'backoffice-suspend-001')
            ->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Revisión administrativa de seguridad',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $target->public_id)
            ->assertJsonPath('data.status', User::STATUS_SUSPENDED)
            ->assertJsonPath('data.system_role', User::ROLE_USER)
            ->assertJsonPath('data.profile_count', 1);

        $this->assertSame(User::STATUS_SUSPENDED, $target->refresh()->status);
        $audit = AuditLog::query()->where('target_type', 'user')->sole();
        $this->assertSame('user.suspended', $audit->action);
        $this->assertSame($target->public_id, $audit->target_id);
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('backoffice-suspend-001', $audit->request_id);
        $this->assertSame('Revisión administrativa de seguridad', $audit->reason);
        $this->assertSame(
            ['after' => User::STATUS_SUSPENDED, 'before' => User::STATUS_ACTIVE],
            $this->sorted($audit->safe_changes['status']),
        );
        $encoded = json_encode($audit->safe_changes, JSON_THROW_ON_ERROR);
        foreach ([
            'suspend-target@example.test',
            'contacto-publico@example.test',
            'contact_email',
            'phone',
            '+542944000111',
            'token',
            'password',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        $this->withToken($targetToken)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.status', User::STATUS_SUSPENDED);
        $this->withToken($targetToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id.'/access')
            ->assertOk()
            ->assertJsonPath('data.effective_state', 'suspended')
            ->assertJsonPath('data.denial_code', 'account_suspended');

        $blocked = [
            $this->withToken($targetToken)->patchJson('/api/v1/me', ['name' => 'No debe cambiar']),
            $this->withToken($targetToken)->getJson('/api/v1/catalogs'),
            $this->withToken($targetToken)->getJson('/api/v1/profiles/'.$profile->public_id),
            $this->withToken($targetToken)->patchJson('/api/v1/profiles/'.$profile->public_id, [
                'display_name' => 'No debe editarse',
            ]),
            $this->withToken($targetToken)->postJson('/api/v1/profiles', [
                'category' => ProfileCategory::Guide->value,
                'display_name' => 'No debe crearse',
            ]),
        ];
        foreach ($blocked as $response) {
            $response->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
        }

        $this->withToken($targetToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->withToken($targetToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(1, Profile::query()->where('owner_user_id', $target->id)->count());
        $this->assertSame(1, AccessGrant::query()->where('profile_id', $profile->id)->value('version'));
    }

    public function test_t11_user_reactivation_restores_the_account_and_audits_the_change(): void
    {
        [, $adminToken] = $this->registerAdmin('reactivate-admin@example.test');
        [$target, $targetToken] = $this->registerAccount('reactivate-target@example.test');
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->withHeader('X-Request-ID', 'backoffice-reactivate-001')
            ->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => User::STATUS_ACTIVE,
                'reason' => 'Revisión finalizada sin hallazgos',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_ACTIVE)
            ->assertJsonPath('data.profile_count', 0);

        $this->assertSame(User::STATUS_ACTIVE, $target->refresh()->status);
        $audit = AuditLog::query()->where('target_type', 'user')->sole();
        $this->assertSame('user.reactivated', $audit->action);
        $this->assertSame('backoffice-reactivate-001', $audit->request_id);
        $this->assertSame(
            ['after' => User::STATUS_ACTIVE, 'before' => User::STATUS_SUSPENDED],
            $this->sorted($audit->safe_changes['status']),
        );

        $this->withToken($targetToken)->getJson('/api/v1/catalogs')->assertOk();
    }

    public function test_t11_profile_suspension_preserves_owner_reads_and_leaves_the_grant_untouched(): void
    {
        [, $adminToken] = $this->registerAdmin('profile-status-admin@example.test');
        [$owner, $ownerToken] = $this->registerAccount('profile-status-owner@example.test');
        $profile = Profile::factory()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Guía habilitada',
            'slug' => 'guia-habilitada-t11',
            'status' => ProfileStatus::Published,
            'completed_at' => $this->clock->now()->subDay(),
            'onboarding_step' => 4,
        ]);
        $grant = $this->grant($profile, AccessGrantStatus::Active);
        $city = City::query()->firstOrFail();

        $this->withHeader('X-Request-ID', 'backoffice-profile-001')
            ->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$profile->public_id.'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Documentación bajo revisión',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.id', $profile->public_id)
            ->assertJsonPath('data.profile.status', ProfileStatus::Suspended->value)
            ->assertJsonPath('data.owner.id', $owner->public_id)
            ->assertJsonPath('data.owner.profile_count', 1)
            ->assertJsonPath('data.access.effective_state', 'suspended')
            ->assertJsonPath('data.access.allowed', false)
            ->assertJsonPath('data.access.denial_code', 'profile_suspended')
            ->assertJsonPath('data.access.grant.status', AccessGrantStatus::Active->value)
            ->assertJsonPath('data.access.grant.version', 1);

        $this->assertSame(ProfileStatus::Suspended, $profile->refresh()->status);
        $this->assertSame(1, AccessGrant::query()->count());
        $grant->refresh();
        $this->assertSame(AccessGrantStatus::Active, $grant->status);
        $this->assertSame(1, $grant->version);
        $this->assertSame('Habilitación de prueba', $grant->reason);

        $audit = AuditLog::query()->where('action', 'profile.suspended')->sole();
        $this->assertSame('profile', $audit->target_type);
        $this->assertSame($profile->public_id, $audit->target_id);
        $this->assertSame('backoffice-profile-001', $audit->request_id);
        $this->assertSame('Documentación bajo revisión', $audit->reason);
        $this->assertSame($owner->public_id, $audit->safe_changes['owner_id']);
        $this->assertSame(1, $audit->safe_changes['access_grant_version']);
        $this->assertSame(
            ['after' => ProfileStatus::Suspended->value, 'before' => ProfileStatus::Published->value],
            $this->sorted($audit->safe_changes['status']),
        );
        $encoded = json_encode($audit->safe_changes, JSON_THROW_ON_ERROR);
        foreach (['profile-status-owner@example.test', 'contact_email', 'phone', 'token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', ProfileStatus::Suspended->value);
        $this->withToken($ownerToken)
            ->getJson('/api/v1/profiles/'.$profile->public_id.'/access')
            ->assertOk()
            ->assertJsonPath('data.denial_code', 'profile_suspended');

        $blocked = [
            $this->withToken($ownerToken)->patchJson('/api/v1/profiles/'.$profile->public_id, [
                'display_name' => 'No debe mutar',
            ]),
            $this->withToken($ownerToken)->putJson('/api/v1/profiles/'.$profile->public_id.'/cities', [
                'city_ids' => [$city->id],
            ]),
            $this->withToken($ownerToken)->postJson('/api/v1/profiles/'.$profile->public_id.'/terms', [
                'version' => 'demo-v1',
                'accepted' => true,
            ]),
        ];
        foreach ($blocked as $response) {
            $response->assertForbidden()->assertJsonPath('error.code', 'profile_suspended');
        }
        $this->assertSame('Guía habilitada', $profile->refresh()->display_name);

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$profile->public_id.'/status', [
                'status' => ProfileStatus::Published->value,
                'reason' => 'Revisión documental aprobada',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.status', ProfileStatus::Published->value)
            ->assertJsonPath('data.access.effective_state', 'active')
            ->assertJsonPath('data.access.allowed', true)
            ->assertJsonPath('data.access.grant.version', 1);

        $this->assertSame(1, AuditLog::query()->where('action', 'profile.restored')->count());
        $this->assertSame(1, AccessGrant::query()->count());
        $this->assertSame(1, AccessGrant::query()->value('version'));
        $this->withToken($ownerToken)
            ->patchJson('/api/v1/profiles/'.$profile->public_id, ['display_name' => 'Guía habilitada otra vez'])
            ->assertOk();
    }

    public function test_t11_restoring_a_profile_never_grants_access_and_keeps_incomplete_ones_in_draft(): void
    {
        [, $adminToken] = $this->registerAdmin('restore-admin@example.test');
        $draft = $this->ownedProfile('Guía sin terminar', 'guia-sin-terminar-t11', ProfileCategory::Guide, ProfileStatus::Draft);

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$draft->public_id.'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Perfil sospechoso durante el onboarding',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.status', ProfileStatus::Suspended->value)
            ->assertJsonPath('data.access.denial_code', 'profile_suspended');

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$draft->public_id.'/status', [
                'status' => ProfileStatus::Published->value,
                'reason' => 'Sospecha descartada, sigue en borrador',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.status', ProfileStatus::Draft->value)
            ->assertJsonPath('data.access.effective_state', 'inactive')
            ->assertJsonPath('data.access.allowed', false)
            ->assertJsonPath('data.access.denial_code', 'subscription_required')
            ->assertJsonPath('data.access.grant', null);

        $this->assertSame(ProfileStatus::Draft, $draft->refresh()->status);
        $this->assertSame(0, AccessGrant::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'profile.suspended')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'profile.restored')->count());
    }

    public function test_t11_admin_accounts_cannot_be_suspended_but_can_be_reactivated(): void
    {
        [$admin, $adminToken] = $this->registerAdmin('self-protect-admin@example.test');
        [$peer] = $this->registerAdmin('peer-admin@example.test');
        [$suspendedPeer] = $this->registerAdmin('suspended-peer-admin@example.test');
        $suspendedPeer->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        foreach ([$admin, $peer] as $protected) {
            $this->withToken($adminToken)
                ->patchJson('/api/v1/admin/users/'.$protected->public_id.'/status', [
                    'status' => User::STATUS_SUSPENDED,
                    'reason' => 'Intento de suspender una cuenta administradora',
                ])
                ->assertConflict()
                ->assertJsonPath('error.code', 'admin_account_protected');
            $this->assertSame(User::STATUS_ACTIVE, $protected->refresh()->status);
        }
        $this->assertSame(0, AuditLog::query()->count());

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$suspendedPeer->public_id.'/status', [
                'status' => User::STATUS_ACTIVE,
                'reason' => 'Reactivación de administrador tras revisión',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_ACTIVE)
            ->assertJsonPath('data.system_role', User::ROLE_ADMIN);
        $this->assertSame(User::STATUS_ACTIVE, $suspendedPeer->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'user.reactivated')->count());
    }

    public function test_t11_writing_the_current_status_answers_ok_without_auditing_a_change(): void
    {
        [, $adminToken] = $this->registerAdmin('idempotent-admin@example.test');
        $target = User::factory()->create(['name' => 'Elena Sosa']);
        $profile = Profile::factory()->create([
            'owner_user_id' => $target->id,
            'status' => ProfileStatus::Suspended,
        ]);

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => User::STATUS_ACTIVE,
                'reason' => 'Confirmación del estado vigente',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_ACTIVE)
            ->assertJsonPath('data.profile_count', 1);
        $this->assertSame(0, AuditLog::query()->count());

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$profile->public_id.'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Confirmación del estado vigente',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.status', ProfileStatus::Suspended->value);
        $this->assertSame(0, AuditLog::query()->count());

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Suspensión efectiva',
            ])
            ->assertOk();
        $this->assertSame(1, AuditLog::query()->count());
        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Suspensión repetida por reintento del cliente',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_SUSPENDED);
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_t11_status_endpoints_reject_unknown_targets_and_invalid_payloads(): void
    {
        [, $adminToken] = $this->registerAdmin('payload-admin@example.test');
        $target = User::factory()->create();
        $profile = Profile::factory()->create();

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.Str::ulid().'/status', [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Usuario inexistente',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.Str::ulid().'/status', [
                'status' => ProfileStatus::Suspended->value,
                'reason' => 'Perfil inexistente',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', [
                'status' => 'admin',
                'reason' => 'no',
                'system_role' => User::ROLE_ADMIN,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['status', 'reason', 'system_role']]]);
        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/profiles/'.$profile->public_id.'/status', [
                'status' => ProfileStatus::Draft->value,
                'reason' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['status']]]);

        $this->assertSame(User::STATUS_ACTIVE, $target->refresh()->status);
        $this->assertSame(ProfileStatus::Draft, $profile->refresh()->status);
        $this->assertSame(0, AuditLog::query()->count());
    }

    private function countQueries(string $token, string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<int, string>
     */
    private function ids(TestResponse $response, string $path): array
    {
        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');

        return collect($data)->pluck($path)->map(static fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
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

    private function ownedProfile(
        string $displayName,
        string $slug,
        ProfileCategory $category,
        ProfileStatus $status,
    ): Profile {
        return Profile::factory()->create([
            'category' => $category,
            'display_name' => $displayName,
            'slug' => $slug,
            'status' => $status,
        ]);
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
