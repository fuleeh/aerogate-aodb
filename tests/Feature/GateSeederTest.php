<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gate;
use Database\Seeders\GateSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_twenty_active_gates_in_deterministic_order(): void
    {
        $this->seed(GateSeeder::class);

        $this->assertSame(20, Gate::query()->count());
        $this->assertSame(20, Gate::query()->active()->count());
        $this->assertSame(
            ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10'],
            Gate::query()->orderBy('allocation_priority')->limit(10)->pluck('code')->all(),
        );
    }

    public function test_b8_has_the_required_time_bound_repair_period(): void
    {
        $this->seed(GateSeeder::class);

        $b8 = Gate::query()->where('code', 'B8')->sole();
        $repair = $b8->unavailabilities()->sole();

        $this->assertTrue($b8->is_active);
        $this->assertInstanceOf(DateTimeImmutable::class, $repair->starts_at);
        $this->assertInstanceOf(DateTimeImmutable::class, $repair->ends_at);
        $this->assertSame('2025-01-10T00:00:00+00:00', $repair->starts_at->format(DATE_ATOM));
        $this->assertSame('2025-01-12T00:00:00+00:00', $repair->ends_at->format(DATE_ATOM));
        $this->assertSame('Scheduled gate repairs', $repair->reason);
    }

    public function test_the_seeder_can_be_run_again_without_creating_duplicates(): void
    {
        $this->seed(GateSeeder::class);
        $this->seed(GateSeeder::class);

        $this->assertSame(20, Gate::query()->count());
        $this->assertDatabaseCount('gate_unavailabilities', 1);
    }
}
