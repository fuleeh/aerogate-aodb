<?php

declare(strict_types=1);

namespace App\Domain\Allocations;

use App\Enums\GateAllocationOutcome;
use App\Models\GateAllocation;
use LogicException;

final readonly class GateAllocationResult
{
    private function __construct(
        public GateAllocationOutcome $outcome,
        public ?GateAllocation $allocation,
    ) {
        if ($outcome === GateAllocationOutcome::NoGateAvailable && $allocation !== null) {
            throw new LogicException('A no-gate result cannot contain an allocation.');
        }

        if ($outcome !== GateAllocationOutcome::NoGateAvailable && $allocation === null) {
            throw new LogicException('A successful allocation result must contain an allocation.');
        }
    }

    public static function allocated(GateAllocation $allocation): self
    {
        return new self(GateAllocationOutcome::Allocated, $allocation);
    }

    public static function alreadyAllocated(GateAllocation $allocation): self
    {
        return new self(GateAllocationOutcome::AlreadyAllocated, $allocation);
    }

    public static function noGateAvailable(): self
    {
        return new self(GateAllocationOutcome::NoGateAvailable, null);
    }
}
