<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Hay una notificación nueva visible para el perfil (T19). Solo IDs: el cliente
 * vuelve a pedir GET /profiles/{id}/notifications. Una notificación de cuenta se
 * emite una vez por cada perfil propio, cada una en su canal, para que el payload
 * coincida con el canal y ningún perfil reciba IDs de otro.
 */
final class NotificationCreated extends RealtimeInvalidation
{
    public function __construct(
        public readonly string $profileId,
        public readonly string $notificationId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::PROFILE_CHANNEL_PREFIX.$this->profileId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array{profile_id: string, notification_id: string} */
    public function broadcastWith(): array
    {
        return ['profile_id' => $this->profileId, 'notification_id' => $this->notificationId];
    }
}
