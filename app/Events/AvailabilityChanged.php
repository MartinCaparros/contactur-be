<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * El calendario de un perfil receptor cambió y su availability_version subió.
 *
 * Va al directorio (buscadores abiertos re-consultan) y al canal propio del
 * perfil (otras pestañas del titular recargan el mes). `months` acota qué meses
 * conviene recargar; el cliente compara `version` para descartar avisos viejos.
 */
final class AvailabilityChanged extends RealtimeInvalidation
{
    /** @param  array<int, string>  $months  meses "YYYY-MM" afectados, ordenados y sin repetir */
    public function __construct(
        public readonly string $profileId,
        public readonly int $version,
        public readonly array $months,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(self::DIRECTORY_CHANNEL),
            new PrivateChannel(self::PROFILE_CHANNEL_PREFIX.$this->profileId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'availability.changed';
    }

    /** @return array{profile_id: string, version: int, months: array<int, string>} */
    public function broadcastWith(): array
    {
        return [
            'profile_id' => $this->profileId,
            'version' => $this->version,
            'months' => $this->months,
        ];
    }
}
