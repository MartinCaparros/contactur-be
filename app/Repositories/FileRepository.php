<?php

namespace App\Repositories;

use App\Data\ValidatedUpload;
use App\Models\File;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;

final class FileRepository
{
    public function create(
        Profile $profile,
        User $uploader,
        string $publicId,
        string $kind,
        string $disk,
        string $path,
        ValidatedUpload $upload,
    ): File {
        return File::query()->create([
            'public_id' => $publicId,
            'owner_profile_id' => $profile->id,
            'uploader_user_id' => $uploader->id,
            'kind' => $kind,
            'disk' => $disk,
            'private_path' => $path,
            'original_filename' => $upload->originalFilename,
            'mime' => $upload->mime,
            'size' => $upload->size,
            'width' => $upload->width,
            'height' => $upload->height,
            'checksum' => $upload->checksum,
            'created_at' => now(),
        ]);
    }

    public function markStorageDeleted(File $file, CarbonImmutable $deletedAt): void
    {
        $file->forceFill(['storage_deleted_at' => $deletedAt])->save();
    }
}
