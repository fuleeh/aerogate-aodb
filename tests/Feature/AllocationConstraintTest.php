<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GateAllocationStatus;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AllocationConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_rejects_overlapping_active_allocations_for_one_gate(): void
    {
        $gate = Gate::factory()->create();
        GateAllocation::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-01 10:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 11:30:00 UTC'),
        ]);

        $this->expectException(QueryException::class);

        GateAllocation::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-01 11:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 12:00:00 UTC'),
        ]);
    }

    public function test_adjacent_half_open_allocations_are_allowed(): void
    {
        $gate = Gate::factory()->create();
        GateAllocation::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-01 10:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 11:30:00 UTC'),
        ]);
        GateAllocation::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-01 11:30:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 13:00:00 UTC'),
        ]);

        self::assertSame(2, $gate->allocations()->count());
    }

    public function test_overlapping_periods_are_allowed_on_different_gates(): void
    {
        $period = [
            'starts_at' => CarbonImmutable::parse('2026-09-01 10:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 11:30:00 UTC'),
        ];

        GateAllocation::factory()->create($period);
        GateAllocation::factory()->create($period);

        self::assertSame(2, GateAllocation::query()->count());
    }

    public function test_one_flight_cannot_have_multiple_active_allocations(): void
    {
        $flight = Flight::factory()->create();
        GateAllocation::factory()->for($flight)->create();

        $this->expectException(QueryException::class);

        GateAllocation::factory()->for($flight)->create();
    }

    public function test_released_history_does_not_block_a_new_allocation(): void
    {
        $gate = Gate::factory()->create();
        $flight = Flight::factory()->create();
        $period = [
            'starts_at' => CarbonImmutable::parse('2026-09-01 10:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 11:30:00 UTC'),
        ];

        GateAllocation::factory()->released()->for($gate)->for($flight)->create($period);
        $active = GateAllocation::factory()->for($gate)->for($flight)->create($period);

        self::assertSame(GateAllocationStatus::Active, $active->status);
        self::assertSame(2, $flight->allocations()->count());
    }

    public function test_the_database_rejects_an_invalid_occupancy_period(): void
    {
        $this->expectException(QueryException::class);

        GateAllocation::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-09-01 11:30:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 10:00:00 UTC'),
        ]);
    }

    public function test_gate_availability_includes_active_allocations(): void
    {
        $gate = Gate::factory()->create();
        GateAllocation::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-01 10:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 11:30:00 UTC'),
        ]);

        $available = Gate::query()->availableDuring(
            CarbonImmutable::parse('2026-09-01 11:00:00 UTC'),
            CarbonImmutable::parse('2026-09-01 12:00:00 UTC'),
        );

        self::assertFalse($available->whereKey($gate->id)->exists());
    }
}
