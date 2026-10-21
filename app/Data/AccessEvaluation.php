<?php

namespace App\Data;

use App\Models\AccessGrant;
use Carbon\CarbonImmutable;

final readonly class AccessEvaluation
{
    /**
     * @param  array<string, bool>  $capabilities
     * @param  array{month_start: string, used: int, limit: int|null, remaining: int|null}|null  $contactQuota
     */
    public function __construct(
        public string $profileId,
        public string $effectiveState,
        public bool $allowed,
        public ?string $denialCode,
        public ?AccessGrant $grant,
        public array $capabilities,
        public ?array $contactQuota,
        public CarbonImmutable $evaluatedAt,
        public ?string $requestedCapability = null,
        public ?int $capabilityLimit = null,
    ) {}
}
