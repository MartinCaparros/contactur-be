<?php

namespace App\Http\Resources;

use App\Models\TermsAcceptance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TermsAcceptance */
final class TermsAcceptanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'profile_id' => $this->getAttribute('profile_public_id'),
            'version' => $this->version,
            'accepted_at' => $this->accepted_at->utc()->toISOString(),
        ];
    }
}
