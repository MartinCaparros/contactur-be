<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->utc()->toISOString(),
            'system_role' => $this->system_role,
            'status' => $this->status,
            'locale' => User::accountLocaleFor(User::laravelLocale($this->locale)),
        ];
    }
}
