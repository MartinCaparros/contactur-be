<?php

namespace App\Http\Resources;

use App\Data\ProfileDashboard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DashboardData del contrato. `access` y `capabilities` salen de AccessResource,
 * el mismo serializador de GET /profiles/{profile}/access, así las formas son
 * idénticas por construcción.
 *
 * @mixin ProfileDashboard
 */
final class DashboardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $access = (new AccessResource($this->access))->resolve($request);
        $metrics = [];

        foreach ($this->metrics as $key => $value) {
            $metrics[] = ['key' => $key, 'value' => $value];
        }

        return [
            'profile_id' => $this->profileId,
            'metrics' => $metrics,
            'capabilities' => $access['capabilities'],
            'access' => $access,
        ];
    }
}
