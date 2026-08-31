<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AllocationAuditReport
{
    public int $anomaliesTotal;

    public function __construct(
        public DateTimeImmutable $auditedAt,
        public int $flightsPending,
        public int $flightsAllocated,
        public int $flightsUnassigned,
        public int $allocationsActive,
        public int $allocationsReleased,
        public int $allocationsCancelled,
        public int $gatesTotal,
        public int $gatesActive,
        public int $gatesInactive,
        public int $gatesOccupiedNow,
        public int $gatesUnavailableNow,
        public int $gatesFreeNow,
        public int $staleUnassignedFlights,
        public int $maintenanceConflicts,
    ) {
        foreach ($this->metrics() as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Audit metrics cannot be negative.');
            }
        }

        if ($this->gatesActive + $this->gatesInactive !== $this->gatesTotal) {
            throw new InvalidArgumentException('Active and inactive gate counts must equal the total gate count.');
        }

        $this->anomaliesTotal = $this->staleUnassignedFlights + $this->maintenanceConflicts;
    }

    /** @return array<string, int> */
    public function metrics(): array
    {
        return [
            'flights_pending' => $this->flightsPending,
            'flights_allocated' => $this->flightsAllocated,
            'flights_unassigned' => $this->flightsUnassigned,
            'allocations_active' => $this->allocationsActive,
            'allocations_released' => $this->allocationsReleased,
            'allocations_cancelled' => $this->allocationsCancelled,
            'gates_total' => $this->gatesTotal,
            'gates_active' => $this->gatesActive,
            'gates_inactive' => $this->gatesInactive,
            'gates_occupied_now' => $this->gatesOccupiedNow,
            'gates_unavailable_now' => $this->gatesUnavailableNow,
            'gates_free_now' => $this->gatesFreeNow,
            'stale_unassigned_flights' => $this->staleUnassignedFlights,
            'maintenance_conflicts' => $this->maintenanceConflicts,
        ];
    }
}
