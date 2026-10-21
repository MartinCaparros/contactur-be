<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Barrido del outbox (T19): reencola correos de notificación que siguen
 * `pending` después de N minutos porque el push posterior al commit falló o el
 * job se perdió. Seguro de repetir: el job solo envía si la fila sigue pending.
 */
final class DispatchPendingNotificationEmails extends Command
{
    /** @var string */
    protected $signature = 'contactur:dispatch-pending-notification-emails
        {--limit=500 : Máximo de correos a reencolar (1–5000)}';

    /** @var string */
    protected $description = 'Reencola correos de notificaciones que quedaron pendientes.';

    public function handle(NotificationService $notifications): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5000],
        ]);

        if ($limit === false) {
            $this->components->error('--limit debe ser un entero entre 1 y 5000.');

            return self::FAILURE;
        }

        $count = $notifications->redispatchPendingEmails(
            (int) config('contactur.notifications.redispatch_after_minutes'),
            $limit,
        );
        $this->components->info('Correos de notificación reencolados: '.$count.'.');

        return self::SUCCESS;
    }
}
