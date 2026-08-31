<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Gate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Gate> */
final class GateFactory extends Factory
{
    protected $model = Gate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('?##'),
            'is_active' => true,
            'allocation_priority' => fake()->numberBetween(1, 1_000),
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
