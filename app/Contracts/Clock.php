<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface Clock
{
    public function now(): CarbonImmutable;
}
