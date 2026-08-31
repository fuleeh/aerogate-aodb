<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Auditing\AllocationAuditReport;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AllocationAuditReader
{
    public function snapshot(DateTimeImmutable $auditedAt, DateTimeImmutable $staleBefore): AllocationAuditReport
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE f.allocation_status = 'pending') AS flights_pending,
                COUNT(*) FILTER (WHERE f.allocation_status = 'allocated') AS flights_allocated,
                COUNT(*) FILTER (WHERE f.allocation_status = 'unassigned') AS flights_unassigned,
                (SELECT COUNT(*) FROM gate_allocations WHERE status = 'active') AS allocations_active,
                (SELECT COUNT(*) FROM gate_allocations WHERE status = 'released') AS allocations_released,
                (SELECT COUNT(*) FROM gate_allocations WHERE status = 'cancelled') AS allocations_cancelled,
                (SELECT COUNT(*) FROM gates) AS gates_total,
                (SELECT COUNT(*) FROM gates WHERE is_active = TRUE) AS gates_active,
                (SELECT COUNT(*) FROM gates WHERE is_active = FALSE) AS gates_inactive,
                (
                    SELECT COUNT(DISTINCT gate_id)
                    FROM gate_allocations
                    WHERE status = 'active'
                      AND occupancy_period @> CAST(? AS timestamptz)
                ) AS gates_occupied_now,
                (
                    SELECT COUNT(DISTINCT gu.gate_id)
                    FROM gate_unavailabilities gu
                    JOIN gates g ON g.id = gu.gate_id AND g.is_active = TRUE
                    WHERE gu.unavailability_period @> CAST(? AS timestamptz)
                ) AS gates_unavailable_now,
                (
                    SELECT COUNT(*)
                    FROM gates g
                    WHERE g.is_active = TRUE
                      AND NOT EXISTS (
                          SELECT 1 FROM gate_allocations ga
                          WHERE ga.gate_id = g.id
                            AND ga.status = 'active'
                            AND ga.occupancy_period @> CAST(? AS timestamptz)
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM gate_unavailabilities gu
                          WHERE gu.gate_id = g.id
                            AND gu.unavailability_period @> CAST(? AS timestamptz)
                      )
                ) AS gates_free_now,
                COUNT(*) FILTER (
                    WHERE f.allocation_status = 'unassigned'
                      AND f.last_observed_at <= CAST(? AS timestamptz)
                ) AS stale_unassigned_flights,
                (
                    SELECT COUNT(DISTINCT ga.id)
                    FROM gate_allocations ga
                    JOIN gate_unavailabilities gu
                      ON gu.gate_id = ga.gate_id
                     AND gu.unavailability_period && ga.occupancy_period
                    WHERE ga.status = 'active'
                ) AS maintenance_conflicts
            FROM flights f
            SQL, [
            $auditedAt,
            $auditedAt,
            $auditedAt,
            $auditedAt,
            $staleBefore,
        ]);

        if ($row === null) {
            throw new RuntimeException('The allocation audit query returned no result.');
        }

        /** @var array<string, int|string> $metrics */
        $metrics = (array) $row;

        return new AllocationAuditReport(
            auditedAt: $auditedAt,
            flightsPending: (int) $metrics['flights_pending'],
            flightsAllocated: (int) $metrics['flights_allocated'],
            flightsUnassigned: (int) $metrics['flights_unassigned'],
            allocationsActive: (int) $metrics['allocations_active'],
            allocationsReleased: (int) $metrics['allocations_released'],
            allocationsCancelled: (int) $metrics['allocations_cancelled'],
            gatesTotal: (int) $metrics['gates_total'],
            gatesActive: (int) $metrics['gates_active'],
            gatesInactive: (int) $metrics['gates_inactive'],
            gatesOccupiedNow: (int) $metrics['gates_occupied_now'],
            gatesUnavailableNow: (int) $metrics['gates_unavailable_now'],
            gatesFreeNow: (int) $metrics['gates_free_now'],
            staleUnassignedFlights: (int) $metrics['stale_unassigned_flights'],
            maintenanceConflicts: (int) $metrics['maintenance_conflicts'],
        );
    }
}
