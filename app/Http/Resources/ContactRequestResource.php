<?php

namespace App\Http\Resources;

use App\Models\ContactRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ContactRequest del contrato. Emisor y destino salen por SafeProfileResource:
 * nunca contact_email, phone ni email de cuenta. `status` es el estado efectivo
 * (expiración por reloj incluida) que el servicio deja en `effective_status`.
 *
 * @mixin ContactRequest
 */
final class ContactRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'sender' => (new SafeProfileResource($this->sender))->resolve($request),
            'recipient' => (new SafeProfileResource($this->recipient))->resolve($request),
            'service_description' => $this->service_description,
            'requested_from' => $this->requested_from,
            'requested_to' => $this->requested_to,
            'requested_city' => $this->requestedCity === null ? null : SafeProfileResource::city($this->requestedCity),
            'status' => (string) $this->getAttribute('effective_status'),
            'availability_status' => $this->availability_status,
            'expires_at' => $this->expires_at->utc()->toISOString(),
            'responded_at' => $this->responded_at?->utc()->toISOString(),
            'cancelled_at' => $this->cancelled_at?->utc()->toISOString(),
            'created_at' => $this->created_at->utc()->toISOString(),
        ];
    }
}
