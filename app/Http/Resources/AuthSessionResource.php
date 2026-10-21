<?php

namespace App\Http\Resources;

use App\Data\AuthSessionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthSessionResult */
final class AuthSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => (new UserResource($this->user))->resolve($request),
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
        ];
    }
}
