<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $actor_user_id
 * @property string $target_type
 * @property string $target_id
 * @property string $action
 * @property string|null $reason
 * @property array<string, mixed> $safe_changes
 * @property string $request_id
 * @property CarbonImmutable $created_at
 * @property-read User $actor
 */
#[Fillable([
    'public_id',
    'actor_user_id',
    'target_type',
    'target_id',
    'action',
    'reason',
    'safe_changes',
    'request_id',
    'created_at',
])]
#[Hidden(['id', 'actor_user_id'])]
final class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'actor_user_id' => 'integer',
            'safe_changes' => 'array',
            'created_at' => UtcDateTime::class,
        ];
    }
}
