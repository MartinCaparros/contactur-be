<?php

namespace App\Http\Resources;

use App\Models\ProfileDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfileDocument */
final class AdminDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $owner = $this->profile->owner;

        return [
            'document' => (new DocumentResource($this->resource))->resolve($request),
            'profile' => (new OwnedProfileSummaryResource($this->profile))->resolve($request),
            'owner' => array_merge((new UserResource($owner))->resolve($request), [
                'created_at' => $owner->created_at?->utc()->toISOString(),
                'profile_count' => (int) $owner->profiles_count,
            ]),
        ];
    }
}
