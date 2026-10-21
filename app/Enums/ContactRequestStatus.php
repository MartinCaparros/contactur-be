<?php

namespace App\Enums;

/** ContactRequestStatus del contrato. `expired` puede ser efectivo (por reloj) antes de persistirse. */
enum ContactRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
