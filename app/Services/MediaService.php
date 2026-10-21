<?php

namespace App\Services;

use App\Contracts\Clock;
use App\Data\ValidatedUpload;
use App\Enums\AccessEffectiveState;
use App\Exceptions\ApiProblemException;
use App\Models\File;
use App\Models\Profile;
use App\Models\ProfileGallery;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\FileRepository;
use App\Repositories\MediaRepository;
use App\Repositories\ProfileRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class MediaService
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly ProfileRepository $profiles,
        private readonly MediaRepository $media,
        private readonly FileRepository $files,
        private readonly AuditLogRepository $auditLogs,
        private readonly AccessService $access,
        private readonly Clock $clock,
    ) {}

    public function ownedProfile(User $actor, string $publicId): Profile
    {
        return $this->profileService->owned($actor, $publicId);
    }

    public function replaceAvatar(
        User $actor,
        Profile $profile,
        ValidatedUpload $upload,
        string $requestId,
    ): File {
        $this->assertEditable($actor, $profile);
        $filePublicId = (string) Str::ulid();
        $disk = 'public';
        $path = 'media/'.$profile->public_id.'/avatar/'.$filePublicId.'.'.$upload->extension;
        $stored = false;
        $previous = null;

        try {
            $file = DB::transaction(function () use (
                $actor,
                $profile,
                $upload,
                $requestId,
                $filePublicId,
                $disk,
                $path,
                &$stored,
                &$previous,
            ): File {
                $locked = $this->profiles->lockById($profile->id);
                $this->assertEditable($actor, $locked);
                $this->store($disk, $path, $upload);
                $stored = true;
                $created = $this->files->create($locked, $actor, $filePublicId, 'avatar', $disk, $path, $upload);
                $previous = $this->media->replaceAvatar($locked, $created);
                $this->auditLogs->create(
                    actor: $actor,
                    targetType: 'profile',
                    targetId: $locked->public_id,
                    action: 'avatar.replaced',
                    reason: 'Actualización de avatar por el titular.',
                    safeChanges: [
                        'avatar_id' => $created->public_id,
                        'previous_avatar_id' => $previous?->public_id,
                    ],
                    requestId: $requestId,
                    createdAt: $this->clock->now(),
                );

                return $created;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        if ($previous instanceof File) {
            $this->removeBinary($previous);
        }

        return $file;
    }

    public function addGallery(
        User $actor,
        Profile $profile,
        ValidatedUpload $upload,
        ?int $position,
        string $requestId,
    ): ProfileGallery {
        $this->assertOwner($actor, $profile);
        $this->access->assertAllowed($actor, $profile, AccessService::CAPABILITY_USE_GALLERY);
        $filePublicId = (string) Str::ulid();
        $disk = 'public';
        $path = 'media/'.$profile->public_id.'/gallery/'.$filePublicId.'.'.$upload->extension;
        $stored = false;

        try {
            return DB::transaction(function () use (
                $actor,
                $profile,
                $upload,
                $position,
                $requestId,
                $filePublicId,
                $disk,
                $path,
                &$stored,
            ): ProfileGallery {
                $locked = $this->profiles->lockById($profile->id);
                $this->assertOwner($actor, $locked);
                $this->access->assertAllowed($actor, $locked, AccessService::CAPABILITY_USE_GALLERY);
                $count = $this->media->galleryCount($locked);
                $maxItems = max(1, (int) config('contactur.uploads.gallery_max_items'));
                if ($count >= $maxItems) {
                    throw new ApiProblemException(422, 'gallery_limit_reached', __('La galería alcanzó el máximo permitido.'));
                }

                $targetPosition = $position ?? $count;
                if ($targetPosition > $count) {
                    throw new ApiProblemException(422, 'validation_failed', __('Los datos enviados no son válidos.'), [
                        'position' => [__('La posición no puede dejar espacios en la galería.')],
                    ]);
                }

                $this->store($disk, $path, $upload);
                $stored = true;
                $file = $this->files->create($locked, $actor, $filePublicId, 'gallery', $disk, $path, $upload);
                $item = $this->media->createGalleryItem($locked, $file, $targetPosition);
                $item->setRelation('file', $file);
                $this->auditLogs->create(
                    actor: $actor,
                    targetType: 'profile_gallery',
                    targetId: $item->public_id,
                    action: 'gallery.added',
                    reason: 'Alta de imagen de galería por el titular.',
                    safeChanges: ['profile_id' => $locked->public_id, 'position' => $targetPosition],
                    requestId: $requestId,
                    createdAt: $this->clock->now(),
                );

                return $item;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    public function deleteGallery(User $actor, string $profilePublicId, string $galleryPublicId, string $requestId): void
    {
        $profile = $this->profileService->owned($actor, $profilePublicId);
        $this->access->assertAllowed($actor, $profile, AccessService::CAPABILITY_USE_GALLERY);

        $file = DB::transaction(function () use ($actor, $profile, $galleryPublicId, $requestId): File {
            $locked = $this->profiles->lockById($profile->id);
            $this->assertOwner($actor, $locked);
            $this->access->assertAllowed($actor, $locked, AccessService::CAPABILITY_USE_GALLERY);
            $item = $this->media->lockGalleryItem($locked, $galleryPublicId);
            if ($item === null) {
                throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
            }

            $file = $item->file;
            $this->media->deleteGalleryItem($item);
            $this->auditLogs->create(
                actor: $actor,
                targetType: 'profile_gallery',
                targetId: $galleryPublicId,
                action: 'gallery.deleted',
                reason: 'Baja de imagen de galería por el titular.',
                safeChanges: ['profile_id' => $locked->public_id],
                requestId: $requestId,
                createdAt: $this->clock->now(),
            );

            return $file;
        });

        $this->removeBinary($file);
    }

    private function assertEditable(User $actor, Profile $profile): void
    {
        $this->assertOwner($actor, $profile);
        $evaluation = $this->access->evaluate($actor, $profile);
        if ($evaluation->effectiveState === AccessEffectiveState::Suspended->value) {
            throw new ApiProblemException(
                403,
                $evaluation->denialCode ?? 'profile_suspended',
                $evaluation->denialCode === 'account_suspended' ? __('La cuenta está suspendida.') : __('El perfil está suspendido.'),
            );
        }
    }

    private function assertOwner(User $actor, Profile $profile): void
    {
        if ($profile->owner_user_id !== $actor->id) {
            throw new ApiProblemException(404, 'not_found', __('El recurso solicitado no existe.'));
        }
    }

    private function store(string $disk, string $path, ValidatedUpload $upload): void
    {
        $stream = @fopen($upload->file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Could not read validated upload.');
        }

        try {
            if (! Storage::disk($disk)->put($path, $stream, ['visibility' => 'public'])) {
                throw new RuntimeException('Could not store validated upload.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function removeBinary(File $file): void
    {
        if ($file->storage_deleted_at !== null) {
            return;
        }

        $storage = Storage::disk($file->disk);
        if (! $storage->exists($file->private_path) || $storage->delete($file->private_path)) {
            $this->files->markStorageDeleted($file, $this->clock->now());
        }
    }
}
