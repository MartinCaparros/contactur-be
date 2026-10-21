<?php

namespace App\Http\Resources;

use App\Data\AccessEvaluation;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @mixin Profile */
final class AdminProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $owner = $this->resource->getRelation('owner');
        if (! $owner instanceof User) {
            throw new LogicException('Admin profile requires its owner.');
        }

        $evaluation = $this->resource->getRelation('accessEvaluation');
        if (! $evaluation instanceof AccessEvaluation) {
            throw new LogicException('Admin profile requires a real access evaluation.');
        }

        return [
            'profile' => (new ProfileResource($this->resource))->resolve($request),
            'owner' => (new AdminUserResource($owner))->resolve($request),
            'access' => (new AccessResource($evaluation))->resolve($request),
        ];
    }
}
