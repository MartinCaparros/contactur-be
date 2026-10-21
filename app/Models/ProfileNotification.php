<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Notificación in-app y fila de outbox del correo (T19/R18). Se inserta solo
 * desde NotificationRepository::insertIfAbsent (ON CONFLICT sobre dedupe_key).
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $profile_id
 * @property NotificationType $type
 * @property array<string, mixed> $safe_data
 * @property string $dedupe_key
 * @property string $email_status
 * @property CarbonImmutable|null $emailed_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
#[Hidden(['id', 'user_id', 'profile_id', 'dedupe_key', 'email_status', 'emailed_at'])]
final class ProfileNotification extends Model
{
    public const EMAIL_NOT_APPLICABLE = 'not_applicable';

    public const EMAIL_PENDING = 'pending';

    public const EMAIL_SENT = 'sent';

    public const EMAIL_SKIPPED_PREFERENCE = 'skipped_preference';

    public const EMAIL_FAILED = 'failed';

    public $timestamps = false;

    protected $table = 'profile_notifications';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'profile_id' => 'integer',
            'type' => NotificationType::class,
            'safe_data' => 'array',
            'emailed_at' => UtcDateTime::class,
            'read_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
        ];
    }
}
