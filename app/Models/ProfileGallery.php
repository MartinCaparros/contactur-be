<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $profile_id
 * @property int $file_id
 * @property int $position
 * @property-read File $file
 */
#[Fillable(['public_id', 'profile_id', 'file_id', 'position'])]
#[Hidden(['id', 'profile_id', 'file_id'])]
final class ProfileGallery extends Model
{
    use HasUlids;

    protected $table = 'profile_gallery';

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

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
