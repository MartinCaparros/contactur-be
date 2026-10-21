<?php

namespace Tests\Feature;

use App\Contracts\Clock;
use App\Enums\AccessGrantStatus;
use App\Enums\ProfileCategory;
use App\Enums\ProfileStatus;
use App\Models\AccessGrant;
use App\Models\AvailabilityDay;
use App\Models\City;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * El reloj queda en 2026-11-10 02:30 UTC = 2026-11-09 23:30 en Buenos Aires:
 * la fecha UTC ya es el 10, pero "hoy" de negocio sigue siendo el 9. Así cada
 * prueba de fechas distingue la fecha local de la fecha UTC.
 */
final class AvailabilityCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const YESTERDAY = '2026-11-08';

    private const TODAY = '2026-11-09';

    private const TOMORROW = '2026-11-10';

    private const DAY_AFTER = '2026-11-11';

    private const LATER = '2026-11-12';

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

    public function test_p12_new_profile_has_no_days_and_only_dates_after_local_today_are_editable(): void
    {
        [$owner, $token] = $this->registerAccount('p12-guide@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Guide, 'Guía calendario');
        [$ushuaia] = $this->cities();
        $profile->cities()->attach($ushuaia->id);
        $this->grant($profile, $owner);

        $this->withToken($token)
            ->getJson($this->url($profile).'?month=2026-11')
            ->assertOk()
            ->assertJsonPath('data.profile_id', $profile->public_id)
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.version', 0)
            ->assertJsonCount(30, 'data.days')
            ->assertJsonPath('data.days.0', ['date' => '2026-11-01', 'available' => false, 'city' => null])
            ->assertJsonPath('data.days.29', ['date' => '2026-11-30', 'available' => false, 'city' => null]);
        $this->assertSame(0, AvailabilityDay::query()->count());

        foreach ([self::YESTERDAY, self::TODAY] as $date) {
            $this->putAvailability($token, $profile, 0, [
                ['date' => $date, 'available' => true, 'city_id' => $ushuaia->id],
            ])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['fields' => ['days.0.date']]]);
        }
        // Tampoco se puede "bloquear" hoy: la regla es por fecha, no por valor.
        $this->putAvailability($token, $profile, 0, [['date' => self::TODAY, 'available' => false]])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['days.0.date']]]);
        $this->assertSame(0, AvailabilityDay::query()->count());
        $this->assertSame(0, $profile->refresh()->availability_version);

        // Mañana local coincide con la fecha UTC actual y sí es editable.
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.version', 1)
            ->assertJsonCount(30, 'data.days')
            ->assertJsonPath('data.days.9.date', self::TOMORROW)
            ->assertJsonPath('data.days.9.available', true)
            ->assertJsonPath('data.days.9.city.id', $ushuaia->id)
            ->assertJsonPath('data.days.9.city.name', $ushuaia->name)
            ->assertJsonPath('data.days.8', ['date' => self::TODAY, 'available' => false, 'city' => null]);

        $this->assertSame(1, AvailabilityDay::query()->count());
        $this->assertDatabaseHas('availability_days', [
            'profile_id' => $profile->id,
            'city_id' => $ushuaia->id,
            'date' => self::TOMORROW,
        ]);
        $this->withToken($token)
            ->getJson($this->url($profile).'?month=2026-11')
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.days.9.available', true);
    }

    public function test_p12_tourism_company_has_no_calendar_on_get_or_put(): void
    {
        [$owner, $token] = $this->registerAccount('p12-company@example.test');
        $profile = $this->createProfile($token, ProfileCategory::TourismCompany, 'Empresa sin calendario');
        [$ushuaia] = $this->cities();
        $profile->cities()->attach($ushuaia->id);
        $this->grant($profile, $owner);

        $this->withToken($token)
            ->getJson($this->url($profile).'?month=2026-11')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'capability_not_available');
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'capability_not_available');

        $this->assertSame(0, AvailabilityDay::query()->count());
        $this->assertSame(0, $profile->refresh()->availability_version);
    }

    public function test_p13_batch_with_one_invalid_item_writes_nothing(): void
    {
        [$owner, $token] = $this->registerAccount('p13-batch@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Driver, 'Conductor lote');
        [$ushuaia] = $this->cities();
        $profile->cities()->attach($ushuaia->id);
        $this->grant($profile, $owner);

        $fields = $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::TODAY, 'available' => true, 'city_id' => $ushuaia->id],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->json('error.fields');
        $this->assertSame(['days.2.date'], array_keys($fields));

        $invalidShapes = [
            'fecha duplicada' => [[
                ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
                ['date' => self::TOMORROW, 'available' => false],
            ], 'days.1.date'],
            'fecha inexistente' => [[
                ['date' => '2026-02-30', 'available' => true, 'city_id' => $ushuaia->id],
            ], 'days.0.date'],
            'available=true sin ciudad' => [[
                ['date' => self::TOMORROW, 'available' => true],
            ], 'days.0.city_id'],
            'available=false con ciudad' => [[
                ['date' => self::TOMORROW, 'available' => false, 'city_id' => $ushuaia->id],
            ], 'days.0.city_id'],
            'available=false con ciudad null' => [[
                ['date' => self::TOMORROW, 'available' => false, 'city_id' => null],
            ], 'days.0.city_id'],
            'available no booleano' => [[
                ['date' => self::TOMORROW, 'available' => 1, 'city_id' => $ushuaia->id],
            ], 'days.0.available'],
        ];

        foreach ($invalidShapes as $case => [$days, $field]) {
            $response = $this->putAvailability($token, $profile, 0, $days)->assertUnprocessable();
            $this->assertArrayHasKey($field, $response->json('error.fields'), $case);
        }

        $this->putAvailability($token, $profile, 0, [])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['days']]]);
        $this->withToken($token)
            ->putJson($this->url($profile), ['expected_version' => 0, 'days' => [], 'toggle' => true])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['toggle']]]);
        $this->withToken($token)
            ->getJson($this->url($profile).'?month=2026-13')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['month']]]);

        $this->assertSame(0, AvailabilityDay::query()->count());
        $this->assertSame(0, $profile->refresh()->availability_version);
    }

    public function test_p13_city_outside_the_profile_is_never_assigned(): void
    {
        [$owner, $token] = $this->registerAccount('p13-city@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Instructor, 'Instructor ciudades');
        [$ushuaia, $calafate, $bariloche] = $this->cities();
        $profile->cities()->attach($ushuaia->id);
        $this->grant($profile, $owner);
        [, $otherToken] = $this->registerAccount('p13-other@example.test');
        $otherProfile = $this->createProfile($otherToken, ProfileCategory::Guide, 'Otro perfil');
        $otherProfile->cities()->attach($calafate->id);

        $fields = $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
            ['date' => self::LATER, 'available' => true, 'city_id' => $bariloche->id],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->json('error.fields');
        $this->assertSame(['days.1.city_id', 'days.2.city_id'], array_keys($fields));
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => 999999],
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['days.0.city_id']]]);

        $this->assertSame(0, AvailabilityDay::query()->count());
        $this->assertSame(0, $profile->refresh()->availability_version);
    }

    public function test_p13_resulting_distinct_cities_respect_city_limit(): void
    {
        [$owner, $token] = $this->registerAccount('p13-limit@example.test');
        $profile = $this->createProfile($token, ProfileCategory::TransportCompany, 'Transporte límite');
        [$ushuaia, $calafate] = $this->cities();
        // Estado heredado: más ciudades que el límite; el calendario debe acotarse igual.
        $profile->cities()->attach([$ushuaia->id, $calafate->id]);
        $grant = $this->grant($profile, $owner, ['city_limit' => 1]);

        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['days']]]);
        $this->assertSame(0, AvailabilityDay::query()->count());

        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertOk()->assertJsonPath('data.version', 1);

        // Un día en otra ciudad sumaría una segunda ciudad futura: rechazado.
        $this->putAvailability($token, $profile, 1, [
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['days']]]);

        // Mover el único día a la otra ciudad deja una sola ciudad: permitido.
        $this->putAvailability($token, $profile, 1, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $calafate->id],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
        ])->assertOk()->assertJsonPath('data.version', 2);

        $grant->forceFill(['city_limit' => 0])->save();
        $this->putAvailability($token, $profile, 2, [
            ['date' => self::LATER, 'available' => true, 'city_id' => $calafate->id],
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['days']]]);

        $grant->forceFill(['city_limit' => null])->save();
        $this->putAvailability($token, $profile, 2, [
            ['date' => self::LATER, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertOk()->assertJsonPath('data.version', 3);

        $this->assertSame(
            [self::TOMORROW => $calafate->id, self::DAY_AFTER => $calafate->id, self::LATER => $ushuaia->id],
            $this->rows($profile),
        );
    }

    public function test_p14_two_edits_with_same_version_one_wins_and_the_other_gets_409(): void
    {
        [$owner, $token] = $this->registerAccount('p14-race@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Guide, 'Guía concurrente');
        [$ushuaia, $calafate] = $this->cities();
        $profile->cities()->attach([$ushuaia->id, $calafate->id]);
        $this->grant($profile, $owner);

        // Dos pestañas leyeron la versión 0.
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => false],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_availability');

        // Sin lost update: la escritura ganadora queda intacta y nada del perdedor se aplicó.
        $this->assertSame([self::TOMORROW => $ushuaia->id], $this->rows($profile));
        $this->assertSame(1, $profile->refresh()->availability_version);
    }

    public function test_p14_repeated_put_never_inverts_state_and_noop_keeps_version(): void
    {
        [$owner, $token] = $this->registerAccount('p14-idempotent@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Guide, 'Guía idempotente');
        [$ushuaia] = $this->cities();
        $profile->cities()->attach($ushuaia->id);
        $this->grant($profile, $owner);
        $enable = [['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id]];
        $disable = [['date' => self::TOMORROW, 'available' => false]];

        $this->putAvailability($token, $profile, 0, $enable)->assertOk()->assertJsonPath('data.version', 1);
        // Reintento con la versión vieja: 409, no un segundo toggle.
        $this->putAvailability($token, $profile, 0, $enable)->assertStatus(409);
        // Reaplicar el mismo estado con la versión vigente es un no-op: 200 y misma versión.
        $this->putAvailability($token, $profile, 1, $enable)
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.days.9.available', true);
        $this->assertSame([self::TOMORROW => $ushuaia->id], $this->rows($profile));

        $this->putAvailability($token, $profile, 1, $disable)
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.days.9.available', false);
        $this->putAvailability($token, $profile, 2, $disable)
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.days.9.available', false);
        // Bloquear un día que nunca estuvo habilitado tampoco cambia nada.
        $this->putAvailability($token, $profile, 2, [['date' => self::LATER, 'available' => false]])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertSame([], $this->rows($profile));
        $this->assertSame(2, $profile->refresh()->availability_version);
    }

    public function test_p14_batch_locks_profile_row_before_comparing_version_and_writing(): void
    {
        [$owner, $token] = $this->registerAccount('p14-lock@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Guide, 'Guía con lock');
        [$ushuaia] = $this->cities();
        $profile->cities()->attach($ushuaia->id);
        $this->grant($profile, $owner);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertOk();

        $lockIndex = $this->firstQueryIndex($queries, 'from "profiles"', 'for update');
        $writeIndex = $this->firstQueryIndex($queries, 'insert into "availability_days"');
        $versionIndex = $this->firstQueryIndex($queries, 'update "profiles"', 'availability_version');
        $this->assertNotNull($lockIndex, 'El lote debe bloquear la fila del perfil.');
        $this->assertNotNull($writeIndex);
        $this->assertNotNull($versionIndex);
        $this->assertLessThan($writeIndex, $lockIndex);
        $this->assertLessThan($versionIndex, $lockIndex);
    }

    public function test_changing_city_of_enabled_day_keeps_a_single_row_per_date(): void
    {
        [$owner, $token] = $this->registerAccount('city-change@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Guide, 'Guía cambia ciudad');
        [$ushuaia, $calafate] = $this->cities();
        $profile->cities()->attach([$ushuaia->id, $calafate->id]);
        $this->grant($profile, $owner);

        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])->assertOk();
        $this->putAvailability($token, $profile, 1, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $calafate->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.days.9.city.id', $calafate->id);

        $this->assertSame([self::TOMORROW => $calafate->id], $this->rows($profile));

        // La base también impide dos ciudades para la misma fecha y perfil.
        $this->expectException(QueryException::class);
        DB::table('availability_days')->insert([
            'profile_id' => $profile->id,
            'city_id' => $ushuaia->id,
            'date' => self::TOMORROW,
        ]);
    }

    public function test_foreign_profile_is_not_found_on_get_and_put(): void
    {
        [$owner, $ownerToken] = $this->registerAccount('calendar-owner@example.test');
        $profile = $this->createProfile($ownerToken, ProfileCategory::Guide, 'Guía ajena');
        [$ushuaia] = $this->cities();
        $profile->cities()->attach($ushuaia->id);
        $this->grant($profile, $owner);
        [, $intruderToken] = $this->registerAccount('calendar-intruder@example.test');

        $this->withToken($intruderToken)
            ->getJson($this->url($profile).'?month=2026-11')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->putAvailability($intruderToken, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
        ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame(0, AvailabilityDay::query()->count());
    }

    public function test_expired_missing_or_suspended_access_cannot_publish_but_owner_can_read(): void
    {
        [$owner, $token] = $this->registerAccount('calendar-denied@example.test');
        [$ushuaia] = $this->cities();
        $noGrant = $this->createProfile($token, ProfileCategory::Guide, 'Sin grant');
        $expired = $this->createProfile($token, ProfileCategory::Guide, 'Grant vencido');
        $this->grant($expired, $owner, ['expires_at' => $this->clock->now()]);
        $grantSuspended = $this->createProfile($token, ProfileCategory::Guide, 'Grant suspendido');
        $this->grant($grantSuspended, $owner, ['status' => AccessGrantStatus::Suspended]);
        $profileSuspended = $this->createProfile($token, ProfileCategory::Guide, 'Perfil suspendido');
        $this->grant($profileSuspended, $owner);
        $profileSuspended->forceFill(['status' => ProfileStatus::Suspended])->save();

        $cases = [
            ['subscription_required', $noGrant],
            ['subscription_expired', $expired],
            ['profile_suspended', $grantSuspended],
            ['profile_suspended', $profileSuspended],
        ];

        foreach ($cases as [$code, $profile]) {
            $profile->cities()->attach($ushuaia->id);
            $this->putAvailability($token, $profile, 0, [
                ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ])
                ->assertForbidden()
                ->assertJsonPath('error.code', $code);
            $this->withToken($token)
                ->getJson($this->url($profile).'?month=2026-11')
                ->assertOk()
                ->assertJsonPath('data.version', 0);
            $this->assertSame(0, $profile->refresh()->availability_version);
        }

        $this->assertSame(0, AvailabilityDay::query()->count());
    }

    public function test_removing_city_with_block_removed_cities_deletes_only_its_future_rows_and_bumps_version(): void
    {
        [$owner, $token] = $this->registerAccount('remove-city@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Guide, 'Guía quita ciudad');
        [$ushuaia, $calafate] = $this->cities();
        $profile->cities()->attach([$ushuaia->id, $calafate->id]);
        $this->grant($profile, $owner);
        // Historial no editable: ayer y hoy en la ciudad que se va a quitar.
        foreach ([self::YESTERDAY, self::TODAY] as $date) {
            AvailabilityDay::query()->create(['profile_id' => $profile->id, 'city_id' => $ushuaia->id, 'date' => $date]);
        }
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
        ])->assertOk()->assertJsonPath('data.version', 1);

        $this->withToken($token)
            ->putJson('/api/v1/profiles/'.$profile->public_id.'/cities', ['city_ids' => [$calafate->id]])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['availability_resolution']]]);
        $this->assertSame(1, $profile->refresh()->availability_version);
        $this->assertSame(2, $profile->cities()->count());

        $this->withToken($token)
            ->putJson('/api/v1/profiles/'.$profile->public_id.'/cities', [
                'city_ids' => [$calafate->id],
                'availability_resolution' => 'block_removed_cities',
            ])
            ->assertOk()
            ->assertJsonPath('data.cities.0.id', $calafate->id);

        $this->assertSame([
            self::YESTERDAY => $ushuaia->id,
            self::TODAY => $ushuaia->id,
            self::DAY_AFTER => $calafate->id,
        ], $this->rows($profile));
        $this->assertSame(2, $profile->refresh()->availability_version);

        // Un editor que seguía en la versión 1 no puede pisar el bloqueo.
        $this->putAvailability($token, $profile, 1, [
            ['date' => self::LATER, 'available' => true, 'city_id' => $calafate->id],
        ])->assertStatus(409)->assertJsonPath('error.code', 'stale_availability');

        // Quitar una ciudad sin días futuros no exige resolución ni cambia la versión.
        $profile->cities()->attach($ushuaia->id);
        $this->withToken($token)
            ->putJson('/api/v1/profiles/'.$profile->public_id.'/cities', ['city_ids' => [$calafate->id]])
            ->assertOk();
        $this->assertSame(2, $profile->refresh()->availability_version);
    }

    public function test_admin_city_downgrade_blocks_future_rows_and_bumps_version(): void
    {
        [$owner, $token] = $this->registerAccount('downgrade-owner@example.test');
        $profile = $this->createProfile($token, ProfileCategory::Guide, 'Guía downgrade');
        [$ushuaia, $calafate] = $this->cities();
        $profile->cities()->attach([$ushuaia->id, $calafate->id]);
        $this->grant($profile, $owner);
        $this->putAvailability($token, $profile, 0, [
            ['date' => self::TOMORROW, 'available' => true, 'city_id' => $ushuaia->id],
            ['date' => self::DAY_AFTER, 'available' => true, 'city_id' => $calafate->id],
        ])->assertOk();
        [$admin, $adminToken] = $this->registerAccount('downgrade-admin@example.test');
        $admin->forceFill(['system_role' => User::ROLE_ADMIN])->save();

        $this->withToken($adminToken)
            ->putJson('/api/v1/admin/profiles/'.$profile->public_id.'/access', [
                'status' => AccessGrantStatus::Active->value,
                'starts_at' => null,
                'expires_at' => null,
                'city_limit' => 1,
                'monthly_contact_limit' => null,
                'gallery_enabled' => false,
                'expected_version' => 1,
                'cities_to_keep' => [$calafate->id],
                'reason' => 'Downgrade de prueba',
            ])
            ->assertOk();

        $this->assertSame([self::DAY_AFTER => $calafate->id], $this->rows($profile));
        $this->assertSame(2, $profile->refresh()->availability_version);
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return TestResponse<Response>
     */
    private function putAvailability(string $token, Profile $profile, int $version, array $days): TestResponse
    {
        return $this->withToken($token)->putJson($this->url($profile), [
            'expected_version' => $version,
            'days' => $days,
        ]);
    }

    private function url(Profile $profile): string
    {
        return '/api/v1/profiles/'.$profile->public_id.'/availability';
    }

    /** @return array<string, int> */
    private function rows(Profile $profile): array
    {
        return AvailabilityDay::query()
            ->where('profile_id', $profile->id)
            ->orderBy('date')
            ->pluck('city_id', 'date')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @param array<int, string> $queries */
    private function firstQueryIndex(array $queries, string ...$needles): ?int
    {
        foreach ($queries as $index => $sql) {
            $matches = array_filter($needles, static fn (string $needle): bool => str_contains($sql, $needle));
            if (count($matches) === count($needles)) {
                return $index;
            }
        }

        return null;
    }

    /** @return array<int, City> */
    private function cities(): array
    {
        return City::query()->orderBy('id')->limit(3)->get()->all();
    }

    /** @param array<string, mixed> $overrides */
    private function grant(Profile $profile, User $actor, array $overrides = []): AccessGrant
    {
        return AccessGrant::query()->create(array_merge([
            'profile_id' => $profile->id,
            'status' => AccessGrantStatus::Active,
            'starts_at' => null,
            'expires_at' => null,
            'city_limit' => null,
            'monthly_contact_limit' => null,
            'gallery_enabled' => false,
            'version' => 1,
            'updated_by' => $actor->id,
            'reason' => 'Habilitación de prueba',
        ], $overrides));
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

    private function createProfile(string $token, ProfileCategory $category, string $displayName): Profile
    {
        $publicId = $this->withToken($token)->postJson('/api/v1/profiles', [
            'category' => $category->value,
            'display_name' => $displayName,
        ])->assertCreated()->json('data.id');

        return Profile::query()->where('public_id', $publicId)->firstOrFail();
    }
}
