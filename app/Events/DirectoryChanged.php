<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Cambió si un perfil aparece en el directorio o cómo se ordena (acceso,
 * suspensión de perfil o cuenta, verificación documental). Los buscadores
 * abiertos re-consultan; el aviso no dice qué cambió ni hacia dónde.
 */
final class DirectoryChanged extends RealtimeInvalidation
{
    public function __construct(public readonly string $profileId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::DIRECTORY_CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'directory.changed';
    }

    /** @return array{profile_id: string} */
    public function broadcastWith(): array
    {
        return ['profile_id' => $this->profileId];
    }
}
