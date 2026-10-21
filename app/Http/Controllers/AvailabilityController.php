<?php

namespace App\Http\Controllers;

use App\Http\Resources\AvailabilityResource;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

final class AvailabilityController extends ApiController
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function show(Request $request, string $profile): AvailabilityResource
    {
        $ownedProfile = $this->availability->ownedReceiver($this->authenticatedUser($request), $profile);
        $data = $this->validatePayload($request, [
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], ['month']);

        return new AvailabilityResource($this->availability->month($ownedProfile, (string) $data['month']));
    }

    public function update(Request $request, string $profile): AvailabilityResource
    {
        $actor = $this->authenticatedUser($request);
        $ownedProfile = $this->availability->ownedReceiver($actor, $profile);
        $data = $this->validatePayload($request, [
            'expected_version' => ['required', 'integer', 'min:0'],
            'days' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('contactur.availability.max_dates')],
            'days.*' => ['required', 'array:date,available,city_id'],
            'days.*.date' => ['required', 'string', 'date_format:Y-m-d', 'distinct'],
            'days.*.available' => ['required', 'boolean:strict'],
            // oneOf del contrato: available=true exige city_id; available=false no lo admite.
            'days.*.city_id' => [
                'required_if:days.*.available,true',
                'missing_if:days.*.available,false',
                'integer',
                'min:1',
            ],
        ], ['expected_version', 'days']);

        $days = [];
        foreach ($data['days'] as $day) {
            $available = $day['available'] === true;
            $days[] = [
                'date' => (string) $day['date'],
                'available' => $available,
                'city_id' => $available ? (int) $day['city_id'] : null,
            ];
        }

        return new AvailabilityResource($this->availability->replace(
            $actor,
            $ownedProfile,
            (int) $data['expected_version'],
            $days,
        ));
    }
}
