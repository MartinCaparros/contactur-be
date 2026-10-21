<?php

namespace App\Data;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;

final readonly class DirectorySearchPage
{
    /**
     * @param  Collection<int, Profile>  $items  cada perfil trae `verified` y `matched_days`
     */
    public function __construct(
        public Collection $items,
        public int $requestedDays,
        public ?string $nextCursor,
        public int $perPage,
        public string $seed,
    ) {}
}
