<?php

namespace Tests\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $current) {}

    public function now(): CarbonImmutable
    {
        return $this->current;
    }

    public function set(CarbonImmutable $current): void
    {
        $this->current = $current;
    }
}
