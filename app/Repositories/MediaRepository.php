<?php

namespace App\Repositories;

use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileGallery;
use Illuminate\Database\Eloquent\Collection;

final class MediaRepository
{
    public function replaceAvatar(Profile $profile, File $file): ?File
    {
        $previous = $profile->avatar_file_id === null
            ? null
            : File::query()->find($profile->avatar_file_id);
        $profile->forceFill(['avatar_file_id' => $file->id])->save();

        return $previous;
    }

    public function galleryCount(Profile $profile): int
    {
        return ProfileGallery::query()->where('profile_id', $profile->id)->count();
    }

    public function createGalleryItem(Profile $profile, File $file, int $position): ProfileGallery
    {
        /** @var Collection<int, ProfileGallery> $items */
        $items = ProfileGallery::query()
            ->where('profile_id', $profile->id)
            ->where('position', '>=', $position)
            ->lockForUpdate()
            ->orderByDesc('position')
            ->get();

        foreach ($items as $item) {
            $item->forceFill(['position' => $item->position + 1])->save();
        }

        return ProfileGallery::query()->create([
            'profile_id' => $profile->id,
            'file_id' => $file->id,
            'position' => $position,
        ]);
    }

    public function lockGalleryItem(Profile $profile, string $publicId): ?ProfileGallery
    {
        return ProfileGallery::query()
            ->where('profile_id', $profile->id)
            ->where('public_id', $publicId)
            ->with('file')
            ->lockForUpdate()
            ->first();
    }

    public function deleteGalleryItem(ProfileGallery $item): void
    {
        $profileId = $item->profile_id;
        $position = $item->position;
        $item->delete();

        /** @var Collection<int, ProfileGallery> $followingItems */
        $followingItems = ProfileGallery::query()
            ->where('profile_id', $profileId)
            ->where('position', '>', $position)
            ->lockForUpdate()
            ->orderBy('position')
            ->get();

        foreach ($followingItems as $followingItem) {
            $followingItem->forceFill(['position' => $followingItem->position - 1])->save();
        }
    }
}
