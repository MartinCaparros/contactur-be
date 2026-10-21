<?php

namespace App\Enums;

enum AccessGrantStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Suspended = 'suspended';
}
