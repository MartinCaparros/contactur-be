<?php

namespace App\Http\Resources;

use App\Models\ProfileNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfileNotification */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type->value,
            // Objeto JSON aun vacío (el contrato exige object, no array).
            'safe_data' => (object) $this->safe_data,
            'read_at' => $this->read_at?->utc()->toISOString(),
            'created_at' => $this->created_at->utc()->toISOString(),
        ];
    }
}
