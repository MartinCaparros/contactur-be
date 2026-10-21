<?php

namespace App\Http\Resources;

use App\Enums\ProfileCategory;
use App\Models\City;
use App\Models\FleetVehicle;
use App\Models\Language;
use App\Models\Profile;
use App\Models\Qualification;
use App\Models\Service;
use App\Models\Specialty;
use App\Models\User;
use App\Support\CatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Profile */
final class ProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->public_id,
            'category' => $this->category->value,
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'onboarding_step' => $this->onboarding_step,
            'completed_at' => $this->completed_at?->utc()->toISOString(),
            'primary_city' => $this->primaryCity === null ? null : $this->city($this->primaryCity),
            'cities' => $this->cities->map(fn (City $city): array => $this->city($city))->all(),
            'languages' => $this->languages->map(fn (Language $item): array => $this->catalogItem($item))->all(),
            'services' => $this->services->map(fn (Service $item): array => $this->catalogItem($item))->all(),
            'avatar' => $this->avatarFile === null || $this->avatarFile->storage_deleted_at !== null
                ? null
                : (new MediaFileResource($this->avatarFile))->resolve($request),
            'gallery' => $this->gallery
                ->filter(static fn ($item): bool => $item->file->storage_deleted_at === null)
                ->map(fn ($item): array => (new GalleryResource($item))->resolve($request))
                ->values()
                ->all(),
            'verified' => (bool) $this->getAttribute('verified'),
        ];

        $actor = $request->user();
        if ($actor instanceof User && $actor->id === $this->owner_user_id) {
            $data['contact_email'] = $this->contact?->contact_email;
            $data['phone'] = $this->contact?->phone;
        }

        return array_merge($data, match ($this->category) {
            ProfileCategory::TourismCompany => [
                'company' => [
                    'legal_name' => $this->companyDetails->legal_name ?? '',
                    'years_active' => $this->companyDetails?->years_active,
                ],
            ],
            ProfileCategory::Guide => [
                'guide' => ['education_summary' => $this->guideDetails?->education_summary],
                'qualifications' => $this->qualificationItems(),
            ],
            ProfileCategory::Driver => [
                'driver' => [
                    'license_type' => $this->driverDetails?->license_type,
                    'vehicle_type' => $this->driverDetails?->vehicle_type,
                    'vehicle_capacity' => $this->driverDetails?->vehicle_capacity,
                ],
            ],
            ProfileCategory::Instructor => [
                'instructor' => ['training_summary' => $this->instructorDetails?->training_summary],
                'specialties' => $this->specialties
                    ->map(fn (Specialty $item): array => $this->catalogItem($item))
                    ->all(),
                'qualifications' => $this->qualificationItems(),
            ],
            ProfileCategory::TransportCompany => [
                'transport_company' => [
                    'legal_name' => $this->transportDetails->legal_name ?? '',
                    'fleet_description' => $this->transportDetails?->fleet_description,
                ],
                'fleet_vehicles' => $this->fleetVehicles->map(static fn (FleetVehicle $vehicle): array => [
                    'id' => $vehicle->id,
                    'vehicle_type' => $vehicle->vehicle_type,
                    'quantity' => $vehicle->quantity,
                    'capacity' => $vehicle->capacity,
                ])->all(),
            ],
        });
    }

    /** @return array{id: int, name: string, province_code: string, country_code: string, timezone: string} */
    private function city(City $city): array
    {
        return [
            'id' => $city->id,
            'name' => $city->name,
            'province_code' => $city->province_code,
            'country_code' => $city->country_code,
            'timezone' => $city->timezone,
        ];
    }

    /** @return array{id: int, code: string, name: string} */
    private function catalogItem(Language|Service|Specialty $item): array
    {
        return ['id' => $item->id, 'code' => $item->code, 'name' => CatalogLabel::translate($item->name)];
    }

    /** @return array<int, array<string, mixed>> */
    private function qualificationItems(): array
    {
        return $this->qualifications->map(static fn (Qualification $qualification): array => [
            'id' => $qualification->id,
            'title' => $qualification->title,
            'institution' => $qualification->institution,
            'completed_on' => $qualification->completed_on->toDateString(),
            'expires_on' => $qualification->expires_on?->toDateString(),
        ])->all();
    }
}
