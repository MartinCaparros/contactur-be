<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryResource;
use App\Http\Resources\MediaFileResource;
use App\Services\MediaService;
use App\Support\UploadInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class MediaController extends ApiController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly UploadInspector $uploads,
    ) {}

    public function avatar(Request $request, string $profile): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $ownedProfile = $this->media->ownedProfile($actor, $profile);
        $data = $this->validatePayload($request, [
            'image' => ['required', 'file', 'max:'.$this->maxKilobytes()],
        ], ['image']);
        $file = $data['image'];
        assert($file instanceof UploadedFile);
        $upload = $this->uploads->inspect($file, UploadInspector::MEDIA, 'image');

        return (new MediaFileResource($this->media->replaceAvatar(
            $actor,
            $ownedProfile,
            $upload,
            (string) $request->attributes->get('request_id'),
        )))->response()->setStatusCode(201);
    }

    public function gallery(Request $request, string $profile): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $ownedProfile = $this->media->ownedProfile($actor, $profile);
        $data = $this->validatePayload($request, [
            'image' => ['required', 'file', 'max:'.$this->maxKilobytes()],
            'position' => ['sometimes', 'integer', 'min:0'],
        ], ['image', 'position']);
        $file = $data['image'];
        assert($file instanceof UploadedFile);
        $upload = $this->uploads->inspect($file, UploadInspector::MEDIA, 'image');
        $item = $this->media->addGallery(
            $actor,
            $ownedProfile,
            $upload,
            array_key_exists('position', $data) ? (int) $data['position'] : null,
            (string) $request->attributes->get('request_id'),
        );

        return (new GalleryResource($item))->response()->setStatusCode(201);
    }

    public function deleteGallery(Request $request, string $profile, string $gallery): JsonResponse
    {
        $this->validatePayload($request, [], []);
        $this->media->deleteGallery(
            $this->authenticatedUser($request),
            $profile,
            $gallery,
            (string) $request->attributes->get('request_id'),
        );

        return response()->json(status: 204);
    }

    private function maxKilobytes(): int
    {
        return (int) ceil(max(1, (int) config('contactur.uploads.media_max_bytes')) / 1024);
    }
}
