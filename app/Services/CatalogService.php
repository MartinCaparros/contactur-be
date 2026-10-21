<?php

namespace App\Services;

use App\Enums\ProfileCategory;
use App\Repositories\CatalogRepository;

final class CatalogService
{
    public function __construct(private readonly CatalogRepository $catalogs) {}

    /**
     * @return array{data: array<string, mixed>, meta: array{next_cursor: string|null, per_page: int, total: int}}
     */
    public function list(?string $search, ?string $category, ?int $cursor, int $perPage): array
    {
        return $this->catalogs->list(
            $search === null ? null : trim($search),
            $category === null ? null : ProfileCategory::from($category),
            $cursor,
            $perPage,
        );
    }
}
