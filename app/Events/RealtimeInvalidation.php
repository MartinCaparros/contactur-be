<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Aviso de invalidación por websocket (R10, D20, diseño "Privacidad y eventos").
 *
 * Contrato común de los eventos de tiempo real:
 * - Solo transportan IDs públicos y versiones. Nunca documentos, email,
 *   teléfono, nombres ni descripciones: el cliente vuelve a pedir el dato por
 *   la API autorizada, que revalida acceso. Un socket viejo que reciba el aviso
 *   no obtiene nada nuevo por este canal.
 * - Se despachan después del commit (ShouldDispatchAfterCommit): si la
 *   transacción hace rollback, no se emite nada.
 * - Se encolan en la cola dedicada `realtime`, que el worker atiende primero.
 *
 * Las propiedades son escalares a propósito: lo que viaja serializado en la
 * cola es exactamente lo mismo que se publica, sin modelos rehidratables.
 */
abstract class RealtimeInvalidation implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public const QUEUE = 'realtime';

    public const DIRECTORY_CHANNEL = 'directory';

    public const PROFILE_CHANNEL_PREFIX = 'profile.';

    public function broadcastQueue(): string
    {
        return self::QUEUE;
    }

    abstract public function broadcastAs(): string;

    /** @return array<string, mixed> */
    abstract public function broadcastWith(): array;
}
