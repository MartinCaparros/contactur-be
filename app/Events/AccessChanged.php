<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * La habilitación efectiva del perfil pudo cambiar (grant, suspensión de perfil
 * o de cuenta). Solo va al canal propio: el cliente del titular vuelve a pedir
 * GET /profiles/{id}/access y, si perdió can_search, abandona el directorio.
 */
final class AccessChanged extends RealtimeInvalidation
{
    public function __construct(public readonly string $profileId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::PROFILE_CHANNEL_PREFIX.$this->profileId)];
    }

    public function broadcastAs(): string
    {
        return 'access.changed';
    }

    /** @return array{profile_id: string} */
    public function broadcastWith(): array
    {
        return ['profile_id' => $this->profileId];
    }
}
