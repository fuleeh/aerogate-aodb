<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\GateUnavailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class GateUnavailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gate_is_unavailable_during_an_overlapping_restriction(): void
    {
        $gate = Gate::factory()->create(['code' => 'B8']);
        GateUnavailability::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-10 10:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 14:00:00 UTC'),
        ]);

        $availableGateIds = Gate::query()
            ->availableDuring(
                CarbonImmutable::parse('2026-09-10 11:00:00 UTC'),
                CarbonImmutable::parse('2026-09-10 12:00:00 UTC'),
            )
            ->pluck('id');

        self::assertNotContains($gate->id, $availableGateIds);
    }

    public function test_touching_half_open_windows_do_not_overlap(): void
    {
        $gate = Gate::factory()->create(['code' => 'B8']);
        GateUnavailability::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-10 10:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 14:00:00 UTC'),
        ]);

        $before = Gate::query()->availableDuring(
            CarbonImmutable::parse('2026-09-10 09:00:00 UTC'),
            CarbonImmutable::parse('2026-09-10 10:00:00 UTC'),
        );
        $after = Gate::query()->availableDuring(
            CarbonImmutable::parse('2026-09-10 14:00:00 UTC'),
            CarbonImmutable::parse('2026-09-10 15:00:00 UTC'),
        );

        self::assertTrue($before->whereKey($gate->id)->exists());
        self::assertTrue($after->whereKey($gate->id)->exists());
    }

    public function test_inactive_gates_are_not_available(): void
    {
        $gate = Gate::factory()->inactive()->create();

        $isAvailable = Gate::query()
            ->availableDuring(
                CarbonImmutable::parse('2026-09-10 10:00:00 UTC'),
                CarbonImmutable::parse('2026-09-10 11:00:00 UTC'),
            )
            ->whereKey($gate->id)
            ->exists();

        self::assertFalse($isAvailable);
    }

    public function test_available_gates_are_ordered_deterministically(): void
    {
        $last = Gate::factory()->create(['allocation_priority' => 20]);
        $second = Gate::factory()->create(['allocation_priority' => 10]);
        $first = Gate::factory()->create(['allocation_priority' => 10]);

        $gateIds = Gate::query()
            ->availableDuring(
                CarbonImmutable::parse('2026-09-10 10:00:00 UTC'),
                CarbonImmutable::parse('2026-09-10 11:00:00 UTC'),
            )
            ->pluck('id')
            ->all();

        self::assertSame([$second->id, $first->id, $last->id], $gateIds);
    }

    public function test_the_database_rejects_an_invalid_unavailability_period(): void
    {
        $gate = Gate::factory()->create();

        $this->expectException(QueryException::class);

        GateUnavailability::factory()->for($gate)->create([
            'starts_at' => CarbonImmutable::parse('2026-09-10 14:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 10:00:00 UTC'),
        ]);
    }

    public function test_an_invalid_availability_query_is_rejected_before_database_access(): void
    {
        $instant = CarbonImmutable::parse('2026-09-10 10:00:00 UTC');

        $this->expectException(InvalidArgumentException::class);

        Gate::query()->availableDuring($instant, $instant)->get();
    }
}
