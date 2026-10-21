<?php

namespace App\Http\Resources;

use App\Models\Reference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Reference del contrato, para las dos partes y para la respuesta de moderación.
 * Autor y destinatario salen por SafeProfileResource: nunca contact_email, phone
 * ni email de cuenta. Sin puntajes ni estrellas: solo el texto. `moderated` y
 * `moderation_reason` reflejan la decisión administrativa vigente; quién moderó
 * no se expone.
 *
 * @mixin Reference
 */
final class ReferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'contact_request_id' => $this->contactRequest->public_id,
            'author' => (new SafeProfileResource($this->author))->resolve($request),
            'subject' => (new SafeProfileResource($this->subject))->resolve($request),
            'body' => $this->body,
            'visibility' => $this->visibility,
            'moderated' => $this->moderated_at !== null,
            'moderation_reason' => $this->moderation_reason,
            'created_at' => $this->created_at->utc()->toISOString(),
        ];
    }
}
