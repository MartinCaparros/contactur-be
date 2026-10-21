<?php

namespace App\Http\Resources;

use App\Models\AvailabilityDay;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** PublicAvailabilityData: solo días disponibles, sin ids internos del perfil. */
final class PublicAvailabilityResource extends JsonResource
{
    /**
     * @param  array{profile_id: string, version: int, days: Collection<int, AvailabilityDay>}  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'profile_id' => $this->resource['profile_id'],
            'version' => $this->resource['version'],
            'available_days' => $this->resource['days']
                ->map(static fn (AvailabilityDay $day): array => [
                    'date' => $day->date,
                    'city' => SafeProfileResource::city($day->city),
                ])
                ->all(),
        ];
    }
}
