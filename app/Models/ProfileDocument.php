<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProfileDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $profile_id
 * @property int $document_type_id
 * @property int $file_id
 * @property int $revision
 * @property string $status
 * @property string|null $registration_number
 * @property CarbonImmutable|null $valid_until
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property-read DocumentType $documentType
 * @property-read File $file
 * @property-read Profile $profile
 */
#[Fillable([
    'public_id',
    'profile_id',
    'document_type_id',
    'file_id',
    'revision',
    'registration_number',
    'valid_until',
    'status',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
])]
#[Hidden(['id', 'profile_id', 'document_type_id', 'file_id', 'reviewed_by'])]
final class ProfileDocument extends Model
{
    /** @use HasFactory<ProfileDocumentFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_RETIRED = 'retired';

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'valid_until' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
