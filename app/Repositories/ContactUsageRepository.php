<?php

namespace App\Repositories;

use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Escrituras de `contact_usage` (cuota mensual de solicitudes enviadas, D22).
 * La lectura para /access sigue en AccessGrantRepository::contactUsage.
 * Ambos métodos deben llamarse dentro de una transacción.
 */
final class ContactUsageRepository
{
    private const TABLE = 'contact_usage';

    /** Upsert de la fila del mes (sin pisar `used`) y bloqueo; devuelve el uso actual. */
    public function lockMonth(Profile $profile, string $monthStart, CarbonImmutable $now): int
    {
        $at = $now->utc()->format('Y-m-d H:i:s.uP');
        // ON CONFLICT DO NOTHING: crea la fila del mes sin pisar `used` si ya existe.
        DB::table(self::TABLE)->insertOrIgnore([
            'profile_id' => $profile->id,
            'month_start' => $monthStart,
            'used' => 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return (int) DB::table(self::TABLE)
            ->where('profile_id', $profile->id)
            ->where('month_start', $monthStart)
            ->lockForUpdate()
            ->value('used');
    }

    public function increment(Profile $profile, string $monthStart, CarbonImmutable $now): void
    {
        DB::table(self::TABLE)
            ->where('profile_id', $profile->id)
            ->where('month_start', $monthStart)
            ->increment('used', 1, ['updated_at' => $now->utc()->format('Y-m-d H:i:s.uP')]);
    }
}
