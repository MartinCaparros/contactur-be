<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Consulta pública de soporte (T19/R23). `ip_hash` nunca es la IP en claro.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property string|null $category
 * @property string $message
 * @property string $status
 * @property string $ip_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $emailed_at
 */
#[Fillable([
    'public_id',
    'name',
    'email',
    'category',
    'message',
    'status',
    'ip_hash',
    'created_at',
    'emailed_at',
])]
#[Hidden(['id', 'ip_hash'])]
final class SupportInquiry extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_DISCARDED = 'discarded';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'created_at' => UtcDateTime::class,
            'emailed_at' => UtcDateTime::class,
        ];
    }
}
