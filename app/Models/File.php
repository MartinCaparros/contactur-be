<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $owner_profile_id
 * @property int $uploader_user_id
 * @property string $kind
 * @property string $disk
 * @property string $private_path
 * @property string $original_filename
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $checksum
 * @property CarbonImmutable|null $storage_deleted_at
 */
#[Fillable([
    'public_id',
    'owner_profile_id',
    'uploader_user_id',
    'kind',
    'disk',
    'private_path',
    'original_filename',
    'mime',
    'size',
    'width',
    'height',
    'checksum',
    'storage_deleted_at',
    'created_at',
])]
#[Hidden(['id', 'owner_profile_id', 'uploader_user_id', 'disk', 'private_path', 'checksum', 'storage_deleted_at'])]
final class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory, HasUlids;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'storage_deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
