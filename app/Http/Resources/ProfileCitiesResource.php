<?php

namespace App\Http\Resources;

use App\Models\City;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Profile */
final class ProfileCitiesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'profile_id' => $this->public_id,
            'cities' => $this->cities->map(static fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name,
                'province_code' => $city->province_code,
                'country_code' => $city->country_code,
                'timezone' => $city->timezone,
            ])->all(),
        ];
    }
}
