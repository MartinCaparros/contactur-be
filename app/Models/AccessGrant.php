<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\AccessGrantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $profile_id
 * @property AccessGrantStatus $status
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $city_limit
 * @property int|null $monthly_contact_limit
 * @property bool $gallery_enabled
 * @property int $version
 * @property int $updated_by
 * @property string $reason
 */
#[Fillable([
    'profile_id',
    'status',
    'starts_at',
    'expires_at',
    'city_limit',
    'monthly_contact_limit',
    'gallery_enabled',
    'version',
    'updated_by',
    'reason',
])]
final class AccessGrant extends Model
{
    protected $primaryKey = 'profile_id';

    public $incrementing = false;

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'status' => AccessGrantStatus::class,
            'starts_at' => UtcDateTime::class,
            'expires_at' => UtcDateTime::class,
            'city_limit' => 'integer',
            'monthly_contact_limit' => 'integer',
            'gallery_enabled' => 'boolean',
            'version' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
