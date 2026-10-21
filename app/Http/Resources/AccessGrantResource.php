<?php

namespace App\Http\Resources;

use App\Models\AccessGrant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccessGrant */
final class AccessGrantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'starts_at' => $this->starts_at?->utc()->toISOString(),
            'expires_at' => $this->expires_at?->utc()->toISOString(),
            'city_limit' => $this->city_limit,
            'monthly_contact_limit' => $this->monthly_contact_limit,
            'gallery_enabled' => $this->gallery_enabled,
            'version' => $this->version,
        ];
    }
}
