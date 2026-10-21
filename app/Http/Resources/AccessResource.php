<?php

namespace App\Http\Resources;

use App\Data\AccessEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccessEvaluation */
final class AccessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'profile_id' => $this->profileId,
            'effective_state' => $this->effectiveState,
            'allowed' => $this->allowed,
            'denial_code' => $this->denialCode,
            'grant' => $this->grant === null
                ? null
                : (new AccessGrantResource($this->grant))->resolve($request),
            'capabilities' => $this->capabilities,
            'contact_quota' => $this->contactQuota,
            'evaluated_at' => $this->evaluatedAt->utc()->toISOString(),
        ];
    }
}
