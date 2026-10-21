<?php

namespace App\Http\Resources;

use App\Models\Reference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AdminReference del contrato: Reference + `moderated_at`. El admin necesita el
 * texto para moderar; no ve contactos, el email de las cuentas ni quién moderó.
 *
 * @mixin Reference
 */
final class AdminReferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reference = (new ReferenceResource($this->resource))->resolve($request);
        $createdAt = $reference['created_at'];
        unset($reference['created_at']);

        // Mismo orden de claves que el schema: moderated_at antes de created_at.
        return [
            ...$reference,
            'moderated_at' => $this->moderated_at?->utc()->toISOString(),
            'created_at' => $createdAt,
        ];
    }
}
