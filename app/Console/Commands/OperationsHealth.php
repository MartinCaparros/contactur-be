<?php

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Models\ProfileNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * T24: salud operativa de queue y scheduler, más allá de "el proceso existe" que mira
 * el healthcheck de Compose. Pensado para monitoreo (cron externo, sonda): sale con 1
 * si algún control está en ALERTA.
 *
 *  - Latido del scheduler: `routes/console.php` lo escribe cada minuto en la cache.
 *  - Colas `realtime` y `default` (Redis): tamaño actual.
 *  - failed_jobs: cualquier fila es un job que agotó reintentos.
 *  - Correos del outbox pendientes hace demasiado (el sweeper corre cada 10 min).
 *  - Solicitudes vencidas sin materializar (el job corre cada 5 min).
 */
final class OperationsHealth extends Command
{
    public const HEARTBEAT_KEY = 'contactur:scheduler:heartbeat';

    /** @var string */
    protected $signature = 'contactur:ops-health
        {--heartbeat-max=150 : Segundos máximos desde el último latido del scheduler}
        {--queue-max=500 : Jobs en cola por encima de los cuales se alerta}
        {--email-max=30 : Minutos máximos de un correo pendiente en el outbox}
        {--expired-max=15 : Minutos máximos de una solicitud vencida sin materializar}
        {--json : Imprime el resultado como JSON}';

    /** @var string */
    protected $description = 'Controla latido del scheduler, colas, failed_jobs y outbox; sale con 1 ante una alerta.';

    public function handle(Clock $clock): int
    {
        $now = $clock->now();
        $checks = [];

        $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        $age = is_string($heartbeat) ? (int) CarbonImmutable::parse($heartbeat)->diffInSeconds($now, true) : null;
        $checks[] = $this->check(
            'scheduler',
            $age !== null && $age <= (int) $this->option('heartbeat-max'),
            $age === null ? 'sin latido registrado' : "último latido hace {$age} s",
        );

        foreach (['realtime', 'default'] as $queue) {
            try {
                $size = Queue::connection('redis')->size($queue);
                $checks[] = $this->check("cola {$queue}", $size <= (int) $this->option('queue-max'), "{$size} jobs");
            } catch (Throwable $exception) {
                $checks[] = $this->check("cola {$queue}", false, 'Redis no disponible: '.$exception::class);
            }
        }

        $failed = DB::table('failed_jobs')->count();
        $checks[] = $this->check('failed_jobs', $failed === 0, "{$failed} jobs fallidos");

        $emailLimit = $now->subMinutes((int) $this->option('email-max'));
        $staleEmails = DB::table('profile_notifications')
            ->where('email_status', ProfileNotification::EMAIL_PENDING)
            ->where('created_at', '<', $emailLimit)
            ->count();
        $checks[] = $this->check('outbox de correo', $staleEmails === 0, "{$staleEmails} pendientes hace más de {$this->option('email-max')} min");

        $expiredLimit = $now->subMinutes((int) $this->option('expired-max'));
        $unmaterialized = DB::table('contact_requests')
            ->where('status', 'pending')
            ->where('expires_at', '<', $expiredLimit)
            ->count();
        $checks[] = $this->check('vencimientos', $unmaterialized === 0, "{$unmaterialized} solicitudes vencidas sin materializar hace más de {$this->option('expired-max')} min");

        $healthy = collect($checks)->every(static fn (array $check): bool => $check['ok']);
        if ($this->option('json')) {
            $this->line((string) json_encode(['healthy' => $healthy, 'checked_at' => $now->toIso8601String(), 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Control', 'Estado', 'Detalle'], array_map(
                static fn (array $check): array => [$check['name'], $check['ok'] ? 'OK' : 'ALERTA', $check['detail']],
                $checks,
            ));
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{name: string, ok: bool, detail: string} */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
