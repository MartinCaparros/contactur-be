<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $sid
 * @property int $user_id
 * @property string $family_id
 * @property string $refresh_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $rotated_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $replaced_by_sid
 */
#[Fillable([
    'sid',
    'user_id',
    'family_id',
    'refresh_hash',
    'expires_at',
    'last_used_at',
    'rotated_at',
    'revoked_at',
    'replaced_by_sid',
    'ip_address',
    'user_agent',
])]
#[Hidden(['refresh_hash'])]
class AuthSession extends Model
{
    use HasUlids;

    protected $primaryKey = 'sid';

    public $incrementing = false;

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    protected $keyType = 'string';

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['sid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
