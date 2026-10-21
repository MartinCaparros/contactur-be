<?php

namespace App\Http\Controllers;

use App\Enums\AccessGrantStatus;
use App\Http\Resources\AccessResource;
use App\Services\AccessService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminAccessController extends ApiController
{
    public function __construct(private readonly AccessService $access) {}

    public function update(Request $request, string $profile): AccessResource
    {
        $strictBoolean = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail(__('El campo debe ser booleano.'));
            }
        };
        $data = $this->validatePayload($request, [
            'status' => ['required', 'string', Rule::enum(AccessGrantStatus::class)],
            'starts_at' => ['present', 'nullable', 'date'],
            'expires_at' => ['present', 'nullable', 'date'],
            'city_limit' => ['present', 'nullable', 'integer', 'min:0'],
            'monthly_contact_limit' => ['present', 'nullable', 'integer', 'min:0'],
            'gallery_enabled' => ['required', $strictBoolean],
            'expected_version' => ['required', 'integer', 'min:0'],
            'cities_to_keep' => ['sometimes', 'array', 'max:100'],
            'cities_to_keep.*' => ['required', 'integer', 'distinct:strict', Rule::exists('cities', 'id')],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'status',
            'starts_at',
            'expires_at',
            'city_limit',
            'monthly_contact_limit',
            'gallery_enabled',
            'expected_version',
            'cities_to_keep',
            'reason',
        ]);

        $payload = [
            'status' => (string) $data['status'],
            'starts_at' => $data['starts_at'] === null ? null : CarbonImmutable::parse((string) $data['starts_at'])->utc(),
            'expires_at' => $data['expires_at'] === null ? null : CarbonImmutable::parse((string) $data['expires_at'])->utc(),
            'city_limit' => $data['city_limit'] === null ? null : (int) $data['city_limit'],
            'monthly_contact_limit' => $data['monthly_contact_limit'] === null ? null : (int) $data['monthly_contact_limit'],
            'gallery_enabled' => (bool) $data['gallery_enabled'],
            'expected_version' => (int) $data['expected_version'],
            'reason' => (string) $data['reason'],
        ];
        if (array_key_exists('cities_to_keep', $data)) {
            $payload['cities_to_keep'] = array_map('intval', $data['cities_to_keep']);
        }

        return new AccessResource($this->access->replaceGrant(
            $this->authenticatedUser($request),
            $profile,
            $payload,
            (string) $request->attributes->get('request_id'),
        ));
    }
}
