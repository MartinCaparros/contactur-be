<?php

namespace App\Repositories;

use App\Enums\ProfileCategory;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\Language;
use App\Models\Service;
use App\Models\Specialty;
use App\Support\CatalogLabel;

final class CatalogRepository
{
    /**
     * @return array{data: array<string, mixed>, meta: array{next_cursor: string|null, per_page: int, total: int}}
     */
    public function list(?string $search, ?ProfileCategory $category, ?int $cursor, int $perPage): array
    {
        $citiesQuery = City::query()
            ->when($search !== null, fn ($query) => $query->where('name', 'ILIKE', '%'.$search.'%'))
            ->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id');
        $totalQuery = City::query()
            ->when($search !== null, fn ($query) => $query->where('name', 'ILIKE', '%'.$search.'%'));
        $cities = $citiesQuery->limit($perPage + 1)->get();
        $hasMore = $cities->count() > $perPage;
        $cities = $cities->take($perPage)->values();

        $documentTypes = DocumentType::query()
            ->when($category !== null, fn ($query) => $query->where('category', $category->value))
            ->orderBy('category')
            ->orderBy('id')
            ->get();

        return [
            'data' => [
                'categories' => array_map(static fn (ProfileCategory $item): array => [
                    'code' => $item->value,
                    'name' => $item->label(),
                    'is_receiver' => $item->isReceiver(),
                    'has_availability' => $item->hasAvailability(),
                ], ProfileCategory::cases()),
                'cities' => $cities->map(static fn (City $city): array => [
                    'id' => $city->id,
                    'name' => $city->name,
                    'province_code' => $city->province_code,
                    'country_code' => $city->country_code,
                    'timezone' => $city->timezone,
                ])->all(),
                'services' => $this->catalogItems(Service::query()->orderBy('id')->get()),
                'languages' => $this->catalogItems(Language::query()->orderBy('id')->get()),
                'specialties' => $this->catalogItems(Specialty::query()->orderBy('id')->get()),
                'document_types' => $documentTypes->map(static fn (DocumentType $type): array => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'category' => $type->category->value,
                    'label' => CatalogLabel::translate($type->label),
                    'requires_expiration' => $type->requires_expiration,
                    'required' => $type->required,
                    'jurisdiction' => $type->jurisdiction,
                ])->all(),
            ],
            'meta' => [
                'next_cursor' => $hasMore ? (string) $cities->last()?->id : null,
                'per_page' => $perPage,
                'total' => $totalQuery->count(),
            ],
        ];
    }

    /**
     * @param  iterable<Language|Service|Specialty>  $items
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function catalogItems(iterable $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $result[] = ['id' => $item->id, 'code' => $item->code, 'name' => CatalogLabel::translate($item->name)];
        }

        return $result;
    }
}
