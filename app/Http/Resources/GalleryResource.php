<?php

namespace App\Http\Resources;

use App\Models\ProfileGallery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ProfileGallery */
final class GalleryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'url' => Storage::disk('public')->url($this->file->private_path),
            'mime_type' => $this->file->mime,
            'width' => $this->file->width,
            'height' => $this->file->height,
            'position' => $this->position,
        ];
    }
}
