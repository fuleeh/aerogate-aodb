<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FlightAllocationStatus;
use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Flight> */
final class FlightFactory extends Factory
{
    protected $model = Flight::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstObservedAt = now()->toImmutable();

        return [
            'provider' => 'fake',
            'external_id' => fake()->unique()->uuid(),
            'airport_icao' => 'EDDF',
            'callsign' => strtoupper(fake()->bothify('??###')),
            'aircraft_icao24' => strtolower(fake()->regexify('[a-f0-9]{6}')),
            'first_observed_at' => $firstObservedAt,
            'last_observed_at' => $firstObservedAt,
            'arrival_at' => $firstObservedAt->addMinutes(30),
            'allocation_status' => FlightAllocationStatus::Pending,
        ];
    }

    public function withoutOptionalMetadata(): self
    {
        return $this->state(fn (): array => [
            'callsign' => null,
            'aircraft_icao24' => null,
            'arrival_at' => null,
        ]);
    }
}
