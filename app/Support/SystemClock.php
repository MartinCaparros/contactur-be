<?php

namespace App\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
