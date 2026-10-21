<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Día habilitado de un perfil receptor. La fila existe solo si el día está
 * disponible; `date` es una fecha local de negocio (Y-m-d), sin hora ni zona.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $city_id
 * @property string $date
 * @property-read City $city
 */
#[Fillable(['profile_id', 'city_id', 'date'])]
#[Hidden(['id', 'profile_id'])]
final class AvailabilityDay extends Model
{
    /** Zona de las fechas de negocio (D12); los timestamps siguen en UTC. */
    public const BUSINESS_TIMEZONE = 'America/Argentina/Buenos_Aires';

    protected $table = 'availability_days';

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['city_id' => 'integer'];
    }
}
