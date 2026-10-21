<?php

namespace App\Enums;

enum ProfileStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Suspended = 'suspended';
}
