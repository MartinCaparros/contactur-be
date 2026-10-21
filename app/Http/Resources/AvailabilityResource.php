<?php

namespace App\Http\Resources;

use App\Data\AvailabilityMonth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AvailabilityMonth */
final class AvailabilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $days = [];

        foreach ($this->days as $day) {
            $city = $day['city'];
            $days[] = [
                'date' => $day['date'],
                'available' => $city !== null,
                'city' => $city === null ? null : [
                    'id' => $city->id,
                    'name' => $city->name,
                    'province_code' => $city->province_code,
                    'country_code' => $city->country_code,
                    'timezone' => $city->timezone,
                ],
            ];
        }

        return [
            'profile_id' => $this->profileId,
            'month' => $this->month,
            'version' => $this->version,
            'days' => $days,
        ];
    }
}
