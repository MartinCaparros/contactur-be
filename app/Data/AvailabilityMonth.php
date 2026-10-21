<?php

namespace App\Data;

use App\Models\City;

final readonly class AvailabilityMonth
{
    /**
     * @param  array<int, array{date: string, city: City|null}>  $days  todos los días del mes; city null = bloqueado
     */
    public function __construct(
        public string $profileId,
        public string $month,
        public int $version,
        public array $days,
    ) {}
}
