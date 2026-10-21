<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\ContactRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Solicitud de contacto (R13/D13). `status` es el estado persistido; el estado
 * efectivo considera además la expiración por reloj (ver effectiveStatus).
 * `requested_from`/`requested_to` son fechas locales de negocio (Y-m-d) y solo
 * dan contexto: nunca reservan disponibilidad.
 *
 * @property int $id
 * @property string $public_id
 * @property int $sender_profile_id
 * @property int $recipient_profile_id
 * @property string $service_description
 * @property string|null $requested_from
 * @property string|null $requested_to
 * @property int|null $requested_city_id
 * @property ContactRequestStatus $status
 * @property string $availability_status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $responded_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Profile $sender
 * @property-read Profile $recipient
 * @property-read City|null $requestedCity
 */
#[Fillable([
    'public_id',
    'sender_profile_id',
    'recipient_profile_id',
    'service_description',
    'requested_from',
    'requested_to',
    'requested_city_id',
    'status',
    'availability_status',
    'expires_at',
    'responded_at',
    'cancelled_at',
    'created_at',
    'updated_at',
])]
#[Hidden(['id', 'sender_profile_id', 'recipient_profile_id', 'requested_city_id'])]
final class ContactRequest extends Model
{
    use HasUlids;

    /**
     * created_at/updated_at los escribe el repositorio con el Clock de la app
     * (expires_at y el cooldown dependen de ellos). Sin timestamps automáticos,
     * además, Eloquent no reformatea esas columnas sin zona antes del cast UTC.
     */
    public $timestamps = false;

    public const AVAILABILITY_AVAILABLE = 'available';

    public const AVAILABILITY_UNAVAILABLE = 'unavailable';

    public const AVAILABILITY_UNSPECIFIED = 'unspecified';

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** Una pendiente con expires_at <= now ya está vencida aunque el job no haya corrido. */
    public function effectiveStatus(CarbonImmutable $now): ContactRequestStatus
    {
        if ($this->status === ContactRequestStatus::Pending && ! $now->lessThan($this->expires_at)) {
            return ContactRequestStatus::Expired;
        }

        return $this->status;
    }

    /** @return BelongsTo<Profile, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'sender_profile_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'recipient_profile_id');
    }

    /** @return BelongsTo<City, $this> */
    public function requestedCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'requested_city_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'sender_profile_id' => 'integer',
            'recipient_profile_id' => 'integer',
            'requested_city_id' => 'integer',
            'status' => ContactRequestStatus::class,
            'expires_at' => UtcDateTime::class,
            'responded_at' => UtcDateTime::class,
            'cancelled_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }
}
