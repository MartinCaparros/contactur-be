<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class CurrentAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => (new UserResource($this->resource))->resolve($request),
            'profiles' => $this->profiles
                ->map(fn ($profile): array => (new OwnedProfileSummaryResource($profile))->resolve($request))
                ->all(),
            'notification_preferences' => $this->notification_preferences ?? [],
        ];
    }
}
