<?php

declare(strict_types=1);

namespace App\Enums;

enum GateAllocationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
