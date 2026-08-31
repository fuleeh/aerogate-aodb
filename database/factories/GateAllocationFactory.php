<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GateAllocationStatus;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GateAllocation> */
final class GateAllocationFactory extends Factory
{
    protected $model = GateAllocation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = now()->toImmutable();

        return [
            'flight_id' => Flight::factory(),
            'gate_id' => Gate::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(90),
            'status' => GateAllocationStatus::Active,
        ];
    }

    public function released(): self
    {
        return $this->state(fn (): array => ['status' => GateAllocationStatus::Released]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => ['status' => GateAllocationStatus::Cancelled]);
    }
}
