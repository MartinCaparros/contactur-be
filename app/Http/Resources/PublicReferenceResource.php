<?php

namespace App\Http\Resources;

use App\Models\Reference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PublicReference de la ficha de directorio: solo id, autor seguro, texto y fecha.
 * Nunca visibilidad, moderación, motivo ni la solicitud de origen.
 *
 * @mixin Reference
 */
final class PublicReferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'author' => (new SafeProfileResource($this->author))->resolve($request),
            'body' => $this->body,
            'created_at' => $this->created_at->utc()->toISOString(),
        ];
    }
}
