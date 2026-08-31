<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Contracts\FlightProviders\ExternalFlightData;
use App\Enums\FlightAllocationStatus;
use App\Models\Flight;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class FlightObservationStore
{
    public function persist(ExternalFlightData $data, DateTimeImmutable $observedAt): FlightObservationWrite
    {
        $wasCreated = DB::affectingStatement(
            <<<'SQL'
                INSERT INTO flights (
                    provider,
                    external_id,
                    airport_icao,
                    callsign,
                    aircraft_icao24,
                    first_observed_at,
                    last_observed_at,
                    arrival_at,
                    allocation_status,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (provider, external_id) DO NOTHING
                SQL,
            [
                $data->provider,
                $data->externalId,
                $data->airport->value,
                $data->callsign,
                $data->aircraftIcao24,
                $observedAt,
                $observedAt,
                $data->arrivalAt,
                FlightAllocationStatus::Pending->value,
                $observedAt,
                $observedAt,
            ],
        ) === 1;

        $flight = Flight::query()
            ->where('provider', $data->provider)
            ->where('external_id', $data->externalId)
            ->lockForUpdate()
            ->sole();

        if (! $wasCreated) {
            $this->refreshExistingFlight($flight, $data, $observedAt);
        }

        return new FlightObservationWrite($flight, $wasCreated);
    }

    private function refreshExistingFlight(
        Flight $flight,
        ExternalFlightData $data,
        DateTimeImmutable $observedAt,
    ): void {
        $lastObservedAt = $flight->last_observed_at;

        if (! $lastObservedAt instanceof DateTimeImmutable) {
            throw new RuntimeException('The flight last observation timestamp is unavailable.');
        }

        $flight->update([
            'airport_icao' => $data->airport->value,
            'callsign' => $data->callsign ?? $flight->callsign,
            'aircraft_icao24' => $data->aircraftIcao24 ?? $flight->aircraft_icao24,
            'last_observed_at' => $lastObservedAt >= $observedAt ? $lastObservedAt : $observedAt,
            'arrival_at' => $data->arrivalAt ?? $flight->arrival_at,
        ]);
    }
}
