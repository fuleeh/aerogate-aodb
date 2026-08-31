<?php

declare(strict_types=1);

namespace App\Enums;

enum GateAllocationOutcome: string
{
    case Allocated = 'allocated';
    case AlreadyAllocated = 'already_allocated';
    case NoGateAvailable = 'no_gate_available';
}
