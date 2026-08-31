<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Auditing\AllocationAuditConfiguration;
use App\Application\Auditing\AllocationAuditService;
use App\Enums\FlightAllocationStatus;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Tests\Fakes\FrozenClock;
use Tests\TestCase;

final class AllocationAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_gate_capacity_allocation_states_and_anomalies_without_writing(): void
    {
        $now = new DateTimeImmutable('2026-08-31T10:00:00+00:00');
        $this->app->instance(ClockInterface::class, new FrozenClock($now));
        $this->app->instance(AllocationAuditConfiguration::class, new AllocationAuditConfiguration(15));

        $freeGate = Gate::factory()->create();
        $occupiedGate = Gate::factory()->create();
        $unavailableGate = Gate::factory()->create();
        Gate::factory()->inactive()->create();

        $occupiedFlight = Flight::factory()->create([
            'allocation_status' => FlightAllocationStatus::Allocated,
        ]);
        GateAllocation::factory()->create([
            'flight_id' => $occupiedFlight,
            'gate_id' => $occupiedGate,
            'starts_at' => $now->modify('-30 minutes'),
            'ends_at' => $now->modify('+60 minutes'),
        ]);

        GateUnavailability::factory()->create([
            'gate_id' => $unavailableGate,
            'starts_at' => $now->modify('-10 minutes'),
            'ends_at' => $now->modify('+30 minutes'),
        ]);

        $conflictedFlight = Flight::factory()->create([
            'allocation_status' => FlightAllocationStatus::Allocated,
        ]);
        GateAllocation::factory()->create([
            'flight_id' => $conflictedFlight,
            'gate_id' => $unavailableGate,
            'starts_at' => $now->modify('+1 day'),
            'ends_at' => $now->modify('+1 day +90 minutes'),
        ]);
        GateUnavailability::factory()->create([
            'gate_id' => $unavailableGate,
            'starts_at' => $now->modify('+1 day +30 minutes'),
            'ends_at' => $now->modify('+1 day +2 hours'),
        ]);

        $historicalFlight = Flight::factory()->create();
        GateAllocation::factory()->released()->create([
            'flight_id' => $historicalFlight,
            'gate_id' => $freeGate,
            'starts_at' => $now->modify('-2 days'),
            'ends_at' => $now->modify('-2 days +90 minutes'),
        ]);
        GateAllocation::factory()->cancelled()->create([
            'flight_id' => $historicalFlight,
            'gate_id' => $freeGate,
            'starts_at' => $now->modify('-1 day'),
            'ends_at' => $now->modify('-1 day +90 minutes'),
        ]);

        Flight::factory()->create([
            'allocation_status' => FlightAllocationStatus::Unassigned,
            'first_observed_at' => $now->modify('-30 minutes'),
            'last_observed_at' => $now->modify('-30 minutes'),
        ]);
        Flight::factory()->create([
            'allocation_status' => FlightAllocationStatus::Unassigned,
            'first_observed_at' => $now->modify('-5 minutes'),
            'last_observed_at' => $now->modify('-5 minutes'),
        ]);

        $this->preventAuditWrites();

        $report = $this->app->make(AllocationAuditService::class)->audit();

        $this->assertSame(1, $report->flightsPending);
        $this->assertSame(2, $report->flightsAllocated);
        $this->assertSame(2, $report->flightsUnassigned);
        $this->assertSame(2, $report->allocationsActive);
        $this->assertSame(1, $report->allocationsReleased);
        $this->assertSame(1, $report->allocationsCancelled);
        $this->assertSame(4, $report->gatesTotal);
        $this->assertSame(3, $report->gatesActive);
        $this->assertSame(1, $report->gatesInactive);
        $this->assertSame(1, $report->gatesOccupiedNow);
        $this->assertSame(1, $report->gatesUnavailableNow);
        $this->assertSame(1, $report->gatesFreeNow);
        $this->assertSame(1, $report->staleUnassignedFlights);
        $this->assertSame(1, $report->maintenanceConflicts);
        $this->assertSame(2, $report->anomaliesTotal);
    }

    private function preventAuditWrites(): void
    {
        $fail = static function (): never {
            throw new RuntimeException('The read-only audit attempted to mutate state.');
        };

        Flight::saving($fail);
        Flight::deleting($fail);
        Gate::saving($fail);
        Gate::deleting($fail);
        GateAllocation::saving($fail);
        GateAllocation::deleting($fail);
        GateUnavailability::saving($fail);
        GateUnavailability::deleting($fail);
    }
}
