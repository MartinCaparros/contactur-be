<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $user_id
 * @property string $purpose
 * @property string $token_hash
 * @property string|null $target_email
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 */
#[Fillable([
    'user_id',
    'purpose',
    'token_hash',
    'target_email',
    'expires_at',
    'consumed_at',
])]
#[Hidden(['token_hash'])]
class AccountToken extends Model
{
    use HasUlids;

    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const PURPOSE_EMAIL_CHANGE = 'email_change';

    public $incrementing = false;

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
