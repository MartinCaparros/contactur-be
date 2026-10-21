<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Correo de una notificación del outbox (R18/P26). Solo transporta el id interno:
 * el contenido se arma al ejecutar, con preferencias y estado de cuenta vigentes.
 * Reintentar o duplicar el job no reenvía: NotificationService::deliverEmail
 * bloquea la fila y solo envía si sigue `pending`.
 */
final class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('default');
    }

    public function handle(NotificationService $notifications): void
    {
        $notifications->deliverEmail($this->notificationId);
    }

    /** Agotados los intentos: `failed` (no vuelve a intentarse ni se cuenta como pendiente). */
    public function failed(?Throwable $exception): void
    {
        app(NotificationService::class)->markEmailFailed($this->notificationId);
    }
}
