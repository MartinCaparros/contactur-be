<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin File */
final class MediaFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'url' => Storage::disk('public')->url($this->private_path),
            'mime_type' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'position' => 0,
        ];
    }
}
