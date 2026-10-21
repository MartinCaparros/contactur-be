<?php

namespace App\Http\Resources;

use App\Enums\ProfileCategory;
use App\Models\City;
use App\Models\Language;
use App\Models\Profile;
use App\Models\Service;
use App\Models\Specialty;
use App\Support\CatalogLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SafeProfile del contrato: whitelist explícita para miembros. Nunca incluye
 * contact_email, phone, email de la cuenta, slug, estado, ids internos ni rutas
 * de archivos privados; avatar y galería son media pública ya procesada.
 *
 * @mixin Profile
 */
final class SafeProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'category' => $this->category->value,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'primary_city' => $this->primaryCity === null ? null : self::city($this->primaryCity),
            'cities' => $this->cities->map(static fn (City $city): array => self::city($city))->all(),
            'languages' => $this->languages->map(static fn (Language $item): array => self::catalogItem($item))->all(),
            'services' => $this->services->map(static fn (Service $item): array => self::catalogItem($item))->all(),
            'avatar' => $this->avatarFile === null || $this->avatarFile->storage_deleted_at !== null
                ? null
                : (new MediaFileResource($this->avatarFile))->resolve($request),
            'gallery' => $this->gallery
                ->filter(static fn ($item): bool => $item->file->storage_deleted_at === null)
                ->map(static fn ($item): array => (new GalleryResource($item))->resolve($request))
                ->values()
                ->all(),
            'verified' => (bool) $this->getAttribute('verified'),
            'category_details' => match ($this->category) {
                ProfileCategory::TourismCompany => [
                    'legal_name' => $this->companyDetails->legal_name ?? '',
                    'years_active' => $this->companyDetails?->years_active,
                ],
                ProfileCategory::Guide => [
                    'education_summary' => $this->guideDetails?->education_summary,
                ],
                ProfileCategory::Driver => [
                    'license_type' => $this->driverDetails?->license_type,
                    'vehicle_type' => $this->driverDetails?->vehicle_type,
                    'vehicle_capacity' => $this->driverDetails?->vehicle_capacity,
                ],
                ProfileCategory::Instructor => [
                    'training_summary' => $this->instructorDetails?->training_summary,
                ],
                ProfileCategory::TransportCompany => [
                    'legal_name' => $this->transportDetails->legal_name ?? '',
                    'fleet_description' => $this->transportDetails?->fleet_description,
                ],
            },
        ];
    }

    /** @return array{id: int, name: string, province_code: string, country_code: string, timezone: string} */
    public static function city(City $city): array
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
    public static function catalogItem(Language|Service|Specialty $item): array
    {
        return ['id' => $item->id, 'code' => $item->code, 'name' => CatalogLabel::translate($item->name)];
    }
}
