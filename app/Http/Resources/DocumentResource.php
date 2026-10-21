<?php

namespace App\Http\Resources;

use App\Contracts\Clock;
use App\Models\ProfileDocument;
use App\Support\CatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfileDocument */
final class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $today = app(Clock::class)->now()->startOfDay();
        $isCurrentlyValid = $this->status === ProfileDocument::STATUS_APPROVED
            && $this->file->storage_deleted_at === null
            && ($this->valid_until === null || $this->valid_until->gte($today));

        return [
            'id' => $this->public_id,
            'profile_id' => $this->profile->public_id,
            'document_type' => [
                'id' => $this->documentType->id,
                'code' => $this->documentType->code,
                'category' => $this->documentType->category->value,
                'label' => CatalogLabel::translate($this->documentType->label),
                'requires_expiration' => $this->documentType->requires_expiration,
                'required' => $this->documentType->required,
                'jurisdiction' => $this->documentType->jurisdiction,
            ],
            'original_filename' => $this->file->original_filename,
            'mime_type' => $this->file->mime,
            'size_bytes' => $this->file->size,
            'registration_number' => $this->registration_number,
            'valid_until' => $this->valid_until?->toDateString(),
            'status' => $this->status,
            'reviewed_at' => $this->reviewed_at?->utc()->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'uploaded_at' => $this->created_at?->utc()->toISOString(),
            'is_currently_valid' => $isCurrentlyValid,
        ];
    }
}
