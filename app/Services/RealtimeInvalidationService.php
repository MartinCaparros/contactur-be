<?php

namespace App\Services;

use App\Events\AccessChanged;
use App\Events\AvailabilityChanged;
use App\Events\DirectoryChanged;
use App\Events\NotificationCreated;
use App\Models\Profile;

/**
 * Punto único de emisión de invalidaciones (R10). Los services de negocio lo
 * llaman dentro de su transacción; los eventos son ShouldDispatchAfterCommit,
 * así que solo salen si esa transacción confirma.
 *
 * Se resuelve el dispatcher en cada llamada (helper event()) para respetar
 * Event::fake() aunque el service se haya construido antes.
 */
final class RealtimeInvalidationService
{
    /**
     * @param  array<int, string>  $changedDates  fechas Y-m-d que efectivamente cambiaron;
     *                                            vacío = lote sin cambios, no se emite nada
     */
    public function availabilityChanged(Profile $profile, array $changedDates): void
    {
        if ($changedDates === []) {
            return;
        }

        $months = array_values(array_unique(array_map(
            static fn (string $date): string => substr($date, 0, 7),
            $changedDates,
        )));
        sort($months);

        event(new AvailabilityChanged($profile->public_id, (int) $profile->availability_version, $months));
    }

    /** Cambió la presencia u orden del perfil en el directorio, sin tocar su acceso. */
    public function directoryChanged(Profile $profile): void
    {
        event(new DirectoryChanged($profile->public_id));
    }

    /** Cambió la habilitación efectiva: afecta al directorio y al cliente del titular. */
    public function accessChanged(Profile $profile): void
    {
        event(new DirectoryChanged($profile->public_id));
        event(new AccessChanged($profile->public_id));
    }

    /** T19: nueva notificación visible para el perfil; solo viajan los dos IDs públicos. */
    public function notificationCreated(Profile $profile, string $notificationPublicId): void
    {
        event(new NotificationCreated($profile->public_id, $notificationPublicId));
    }
}
