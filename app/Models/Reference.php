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
 * Referencia textual bilateral posterior a un match aceptado (R17/D14). No es una
 * calificación ni afirma que un servicio se haya realizado: solo texto libre.
 *
 * `visibility` la decide el destinatario (`subject`); la moderación administrativa
 * (`moderated_at`) la anula sin tocar esa elección. El texto (`body`) es inmutable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $contact_request_id
 * @property int $author_profile_id
 * @property int $subject_profile_id
 * @property string $body
 * @property string $visibility
 * @property CarbonImmutable|null $moderated_at
 * @property int|null $moderated_by
 * @property string|null $moderation_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ContactRequest $contactRequest
 * @property-read Profile $author
 * @property-read Profile $subject
 */
#[Fillable([
    'public_id',
    'contact_request_id',
    'author_profile_id',
    'subject_profile_id',
    'body',
    'visibility',
    'moderated_at',
    'moderated_by',
    'moderation_reason',
    'created_at',
    'updated_at',
])]
#[Hidden(['id', 'contact_request_id', 'author_profile_id', 'subject_profile_id', 'moderated_by'])]
final class Reference extends Model
{
    use HasUlids;

    public const VISIBILITY_HIDDEN = 'hidden';

    public const VISIBILITY_VISIBLE = 'visible';

    public const VISIBILITIES = [self::VISIBILITY_HIDDEN, self::VISIBILITY_VISIBLE];

    /** Las fechas las escribe el repositorio con el Clock de la app. */
    public $timestamps = false;

    protected $table = 'references';

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function isModerated(): bool
    {
        return $this->moderated_at !== null;
    }

    /** @return BelongsTo<ContactRequest, $this> */
    public function contactRequest(): BelongsTo
    {
        return $this->belongsTo(ContactRequest::class, 'contact_request_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'author_profile_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'subject_profile_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'contact_request_id' => 'integer',
            'author_profile_id' => 'integer',
            'subject_profile_id' => 'integer',
            'moderated_by' => 'integer',
            'moderated_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }
}
