<?php

declare(strict_types=1);

namespace App\Enums;

enum FlightAllocationStatus: string
{
    case Pending = 'pending';
    case Allocated = 'allocated';
    case Unassigned = 'unassigned';
}
