<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Filtros de búsqueda ya validados y normalizados. La forma normalizada es la
 * que se liga al cursor: dos requests con los mismos filtros efectivos producen
 * la misma huella aunque difieran en mayúsculas o espacios del nombre.
 */
final readonly class DirectorySearchFilters
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    public function __construct(
        public ?string $type,
        public ?string $name,
        public ?int $cityId,
        public ?string $dateFrom,
        public ?string $dateTo,
        public string $matchMode,
        public ?bool $verified,
    ) {}

    public function hasRange(): bool
    {
        return $this->dateFrom !== null && $this->dateTo !== null;
    }

    /** Días pedidos, inclusivo; 0 sin rango. */
    public function requestedDays(): int
    {
        if ($this->dateFrom === null || $this->dateTo === null) {
            return 0;
        }

        return (int) CarbonImmutable::parse($this->dateFrom)->diffInDays(CarbonImmutable::parse($this->dateTo)) + 1;
    }

    /** Huella estable de los filtros efectivos; match_mode solo cuenta con rango. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'type' => $this->type,
            'name' => $this->name,
            'city_id' => $this->cityId,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'match_mode' => $this->hasRange() ? $this->matchMode : null,
            'verified' => $this->verified,
        ], JSON_THROW_ON_ERROR));
    }
}
