<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Se compone sobre UserResource para heredar su allowlist: password,
        // remember_token y pending_email nunca entran en la respuesta admin.
        // El idioma es una preferencia personal: no forma parte del contrato AdminUser.
        $user = (new UserResource($this->resource))->resolve($request);
        unset($user['locale']);

        return array_merge($user, [
            'created_at' => $this->created_at?->utc()->toISOString(),
            'profile_count' => (int) $this->profiles_count,
        ]);
    }
}
