<?php

namespace Tests\Feature;

use App\Console\Commands\OperationsHealth;
use App\Contracts\Clock;
use App\Models\ProfileNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * T24: salud operativa (latido del scheduler, failed_jobs, outbox) y los guardas de
 * los comandos de volumen, que nunca deben correr fuera de una base `*_perf`.
 */
final class OperationsHealthTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-10T15:00:00Z'));
        $this->app->instance(Clock::class, $this->clock);
        Cache::forget(OperationsHealth::HEARTBEAT_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(OperationsHealth::HEARTBEAT_KEY);
        parent::tearDown();
    }

    public function test_the_scheduled_heartbeat_is_what_turns_the_scheduler_check_green(): void
    {
        $this->artisan('contactur:ops-health')
            ->expectsOutputToContain('sin latido registrado')
            ->assertFailed();

        $heartbeat = collect(app(Schedule::class)->events())
            ->first(static fn (Event $event): bool => $event->description === 'contactur:scheduler-heartbeat');
        $this->assertNotNull($heartbeat, 'routes/console.php programa el latido');
        $this->assertSame('* * * * *', $heartbeat->expression);
        $heartbeat->run($this->app);

        $this->artisan('contactur:ops-health')
            ->expectsOutputToContain('último latido hace 0 s')
            ->assertSuccessful();

        // Un latido viejo (scheduler caído) vuelve a alertar.
        $this->clock->set($this->clock->now()->addMinutes(3));
        $this->artisan('contactur:ops-health')
            ->expectsOutputToContain('último latido hace 180 s')
            ->assertFailed();
    }

    public function test_failed_jobs_and_a_stale_outbox_email_raise_alerts_in_json(): void
    {
        Cache::put(OperationsHealth::HEARTBEAT_KEY, $this->clock->now()->toIso8601String(), 3600);
        $user = User::factory()->create();
        DB::table('profile_notifications')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'profile_id' => null,
            'type' => 'welcome',
            'safe_data' => '{}',
            'dedupe_key' => 'ops-health-test:'.Str::ulid(),
            'email_status' => ProfileNotification::EMAIL_PENDING,
            'created_at' => $this->clock->now()->subHour(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: prueba',
            'failed_at' => $this->clock->now(),
        ]);

        $this->artisan('contactur:ops-health')
            ->expectsOutputToContain('1 jobs fallidos')
            ->expectsOutputToContain('1 pendientes hace más de 30 min')
            ->assertFailed();
        // En JSON todo sale en una sola escritura: se comprueba el veredicto.
        $this->artisan('contactur:ops-health', ['--json' => true])
            ->expectsOutputToContain('"healthy": false')
            ->assertFailed();

        DB::table('failed_jobs')->delete();
        DB::table('profile_notifications')->update(['email_status' => 'sent']);
        $this->artisan('contactur:ops-health', ['--json' => true])
            ->expectsOutputToContain('"healthy": true')
            ->assertSuccessful();
    }

    public function test_volume_commands_refuse_to_run_outside_a_perf_database(): void
    {
        $this->artisan('contactur:perf-seed', ['--profiles' => 10])
            ->expectsOutputToContain('solo corre sobre una base descartable *_perf')
            ->assertFailed();
        $this->artisan('contactur:perf-search')
            ->expectsOutputToContain('solo corre sobre la base descartable *_perf')
            ->assertFailed();
        $this->assertSame(0, DB::table('users')->count());
    }
}
