<?php

namespace App\Data;

use App\Models\Profile;
use App\Models\Reference;
use Illuminate\Database\Eloquent\Collection;

final readonly class DirectoryProfile
{
    /**
     * @param  array<int, array{document_type: array{code: string, label: string}, status: string, valid_until: string|null, is_currently_valid: bool}>  $documentStatuses
     * @param  Collection<int, Reference>  $visibleReferences  visibles y no moderadas, más nuevas primero (T20)
     */
    public function __construct(
        public Profile $profile,
        public array $documentStatuses,
        public Collection $visibleReferences,
    ) {}
}
