<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Gate;
use App\Models\GateUnavailability;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GateUnavailability> */
final class GateUnavailabilityFactory extends Factory
{
    protected $model = GateUnavailability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+1 month');

        return [
            'gate_id' => Gate::factory(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+4 hours'),
            'reason' => 'Scheduled maintenance',
        ];
    }
}
