<?php

namespace App\Enums;

enum AccessEffectiveState: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';
}
