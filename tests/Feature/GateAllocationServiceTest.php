<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Allocations\GateAllocationService;
use App\Enums\FlightAllocationStatus;
use App\Enums\GateAllocationOutcome;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GateAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private GateAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aerogate.occupancy_duration_minutes', 90);
        $this->service = $this->app->make(GateAllocationService::class);
    }

    public function test_it_allocates_the_highest_priority_available_gate(): void
    {
        Gate::factory()->create(['code' => 'B2', 'allocation_priority' => 20]);
        $preferredGate = Gate::factory()->create(['code' => 'A1', 'allocation_priority' => 10]);
        $flight = $this->flightObservedAt('2026-08-31 10:00:00');

        $result = $this->service->allocate($flight);

        $this->assertSame(GateAllocationOutcome::Allocated, $result->outcome);
        $this->assertNotNull($result->allocation);
        $allocatedGate = $result->allocation->gate;
        $this->assertNotNull($allocatedGate);
        $this->assertTrue($allocatedGate->is($preferredGate));
        $this->assertSame(FlightAllocationStatus::Allocated, $flight->refresh()->allocation_status);
    }

    public function test_maintenance_excludes_a_gate_for_the_occupancy_window(): void
    {
        $closedGate = Gate::factory()->create(['allocation_priority' => 1]);
        $availableGate = Gate::factory()->create(['allocation_priority' => 2]);
        GateUnavailability::factory()->create([
            'gate_id' => $closedGate->id,
            'starts_at' => CarbonImmutable::parse('2026-08-31 10:30:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-31 11:00:00 UTC'),
        ]);

        $result = $this->service->allocate($this->flightObservedAt('2026-08-31 10:00:00'));

        $this->assertNotNull($result->allocation);
        $allocatedGate = $result->allocation->gate;
        $this->assertNotNull($allocatedGate);
        $this->assertTrue($allocatedGate->is($availableGate));
    }

    public function test_an_existing_allocation_excludes_an_occupied_gate(): void
    {
        $occupiedGate = Gate::factory()->create(['allocation_priority' => 1]);
        $availableGate = Gate::factory()->create(['allocation_priority' => 2]);
        GateAllocation::factory()->create([
            'gate_id' => $occupiedGate->id,
            'starts_at' => CarbonImmutable::parse('2026-08-31 09:30:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-31 10:30:00 UTC'),
        ]);

        $result = $this->service->allocate($this->flightObservedAt('2026-08-31 10:00:00'));

        $this->assertNotNull($result->allocation);
        $allocatedGate = $result->allocation->gate;
        $this->assertNotNull($allocatedGate);
        $this->assertTrue($allocatedGate->is($availableGate));
    }

    public function test_a_gate_is_reusable_at_an_exact_half_open_boundary(): void
    {
        $gate = Gate::factory()->create();
        GateAllocation::factory()->create([
            'gate_id' => $gate->id,
            'starts_at' => CarbonImmutable::parse('2026-08-31 08:30:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-31 10:00:00 UTC'),
        ]);

        $result = $this->service->allocate($this->flightObservedAt('2026-08-31 10:00:00'));

        $this->assertSame(GateAllocationOutcome::Allocated, $result->outcome);
        $this->assertNotNull($result->allocation);
        $allocatedGate = $result->allocation->gate;
        $this->assertNotNull($allocatedGate);
        $this->assertTrue($allocatedGate->is($gate));
    }

    public function test_no_capacity_is_an_explicit_unassigned_result(): void
    {
        $gate = Gate::factory()->create();
        GateAllocation::factory()->create([
            'gate_id' => $gate->id,
            'starts_at' => CarbonImmutable::parse('2026-08-31 09:30:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-08-31 10:30:00 UTC'),
        ]);
        $flight = $this->flightObservedAt('2026-08-31 10:00:00');

        $result = $this->service->allocate($flight);

        $this->assertSame(GateAllocationOutcome::NoGateAvailable, $result->outcome);
        $this->assertNull($result->allocation);
        $this->assertSame(FlightAllocationStatus::Unassigned, $flight->refresh()->allocation_status);
    }

    public function test_an_unassigned_flight_can_be_retried_when_capacity_becomes_available(): void
    {
        $flight = $this->flightObservedAt('2026-08-31 10:00:00');

        $firstResult = $this->service->allocate($flight);
        Gate::factory()->create();
        $secondResult = $this->service->allocate($flight);

        $this->assertSame(GateAllocationOutcome::NoGateAvailable, $firstResult->outcome);
        $this->assertSame(GateAllocationOutcome::Allocated, $secondResult->outcome);
        $this->assertSame(FlightAllocationStatus::Allocated, $flight->refresh()->allocation_status);
    }

    public function test_repeated_allocation_returns_the_existing_active_allocation(): void
    {
        Gate::factory()->create();
        $flight = $this->flightObservedAt('2026-08-31 10:00:00');

        $firstResult = $this->service->allocate($flight);
        $secondResult = $this->service->allocate($flight);

        $this->assertSame(GateAllocationOutcome::Allocated, $firstResult->outcome);
        $this->assertSame(GateAllocationOutcome::AlreadyAllocated, $secondResult->outcome);
        $this->assertNotNull($firstResult->allocation);
        $this->assertNotNull($secondResult->allocation);
        $this->assertTrue($secondResult->allocation->is($firstResult->allocation));
        $this->assertDatabaseCount('gate_allocations', 1);
    }

    public function test_inactive_gates_are_not_allocated(): void
    {
        Gate::factory()->inactive()->create();

        $result = $this->service->allocate($this->flightObservedAt('2026-08-31 10:00:00'));

        $this->assertSame(GateAllocationOutcome::NoGateAvailable, $result->outcome);
    }

    private function flightObservedAt(string $timestamp): Flight
    {
        $observedAt = CarbonImmutable::parse($timestamp, 'UTC');

        return Flight::factory()->create([
            'first_observed_at' => $observedAt,
            'last_observed_at' => $observedAt,
        ]);
    }
}
