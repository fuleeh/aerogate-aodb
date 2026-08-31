<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Allocations\GateAllocationService;
use App\Application\Ingestion\FlightIngestionService;
use App\Contracts\FlightProviders\ArrivalQuery;
use App\Contracts\FlightProviders\ExternalFlightData;
use App\Domain\Allocations\OccupancyWindowPolicy;
use App\Domain\Flights\AirportIcao;
use App\Enums\FlightAllocationStatus;
use App\Infrastructure\Persistence\FlightObservationStore;
use App\Models\Flight;
use App\Models\Gate;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fakes\FakeFlightProvider;
use Tests\Fakes\FrozenClock;
use Tests\TestCase;

final class FlightIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_allocates_a_normalized_arrival(): void
    {
        Gate::factory()->create();
        $data = $this->flightData('flight-1');

        $summary = $this->service(FakeFlightProvider::returning($data))->ingest($this->arrivalQuery());

        $this->assertSame(1, $summary->created);
        $this->assertSame(0, $summary->updated);
        $this->assertSame(1, $summary->allocated);
        $this->assertSame(0, $summary->unassigned);
        $this->assertSame(0, $summary->failed);
        $this->assertSame(1, $summary->processed());

        $flight = Flight::query()->sole();
        $this->assertSame(FlightAllocationStatus::Allocated, $flight->allocation_status);
        $this->assertSame('2026-08-31T10:00:00+00:00', $this->timestamp($flight, 'first_observed_at'));
        $this->assertSame('2026-08-31T10:00:00+00:00', $this->timestamp($flight, 'last_observed_at'));
        $this->assertDatabaseCount('gate_allocations', 1);
    }

    public function test_repeated_ingestion_preserves_one_flight_and_one_allocation(): void
    {
        Gate::factory()->create();
        $data = $this->flightData('flight-1');

        $firstSummary = $this->service(
            FakeFlightProvider::returning($data),
            '2026-08-31T10:00:00+00:00',
        )->ingest($this->arrivalQuery());
        $secondSummary = $this->service(
            FakeFlightProvider::returning($data),
            '2026-08-31T10:05:00+00:00',
        )->ingest($this->arrivalQuery());

        $this->assertSame(1, $firstSummary->created);
        $this->assertSame(1, $secondSummary->updated);
        $this->assertSame(1, $secondSummary->allocated);
        $this->assertDatabaseCount('flights', 1);
        $this->assertDatabaseCount('gate_allocations', 1);

        $flight = Flight::query()->sole();
        $this->assertSame('2026-08-31T10:00:00+00:00', $this->timestamp($flight, 'first_observed_at'));
        $this->assertSame('2026-08-31T10:05:00+00:00', $this->timestamp($flight, 'last_observed_at'));
    }

    public function test_missing_new_optional_data_does_not_erase_known_metadata(): void
    {
        $known = Flight::factory()->create([
            'provider' => 'fake',
            'external_id' => 'flight-1',
            'callsign' => 'DLH123',
            'aircraft_icao24' => '3c6444',
            'arrival_at' => new DateTimeImmutable('2026-08-31T09:30:00+00:00'),
            'first_observed_at' => new DateTimeImmutable('2026-08-31T09:00:00+00:00'),
            'last_observed_at' => new DateTimeImmutable('2026-08-31T09:00:00+00:00'),
        ]);
        Gate::factory()->create();
        $data = new ExternalFlightData(
            provider: 'fake',
            externalId: 'flight-1',
            airport: new AirportIcao('EDDF'),
            callsign: null,
            aircraftIcao24: null,
            arrivalAt: null,
        );

        $this->service(FakeFlightProvider::returning($data))->ingest($this->arrivalQuery());

        $known->refresh();
        $this->assertSame('DLH123', $known->callsign);
        $this->assertSame('3c6444', $known->aircraft_icao24);
        $this->assertSame('2026-08-31T09:30:00+00:00', $this->timestamp($known, 'arrival_at'));
    }

    public function test_capacity_exhaustion_is_counted_as_unassigned(): void
    {
        $summary = $this->service(
            FakeFlightProvider::returning($this->flightData('flight-1')),
        )->ingest($this->arrivalQuery());

        $this->assertSame(1, $summary->created);
        $this->assertSame(0, $summary->allocated);
        $this->assertSame(1, $summary->unassigned);
        $this->assertSame(FlightAllocationStatus::Unassigned, Flight::query()->sole()->allocation_status);
    }

    public function test_one_item_failure_is_rolled_back_and_does_not_stop_later_items(): void
    {
        Gate::factory()->count(2)->create();
        Flight::factory()->create([
            'provider' => 'fake',
            'external_id' => 'poison',
        ]);
        Flight::updating(static function (Flight $flight): void {
            if ($flight->external_id === 'poison') {
                throw new RuntimeException('Simulated item-specific failure.');
            }
        });

        try {
            $summary = $this->service(FakeFlightProvider::returning(
                $this->flightData('flight-1'),
                $this->flightData('poison'),
                $this->flightData('flight-2'),
            ))->ingest($this->arrivalQuery());
        } finally {
            Flight::flushEventListeners();
        }

        $this->assertSame(2, $summary->created);
        $this->assertSame(2, $summary->allocated);
        $this->assertSame(1, $summary->failed);
        $this->assertSame(3, $summary->processed());
        $this->assertSame('poison', $summary->failures[0]->externalId);
        $this->assertSame('Simulated item-specific failure.', $summary->failures[0]->exception->getMessage());
        $this->assertDatabaseCount('gate_allocations', 2);
    }

    public function test_an_older_replayed_run_cannot_move_last_observation_backwards(): void
    {
        Gate::factory()->create();
        $data = $this->flightData('flight-1');
        $this->service(
            FakeFlightProvider::returning($data),
            '2026-08-31T10:05:00+00:00',
        )->ingest($this->arrivalQuery());

        $this->service(
            FakeFlightProvider::returning($data),
            '2026-08-31T10:00:00+00:00',
        )->ingest($this->arrivalQuery());

        $this->assertSame(
            '2026-08-31T10:05:00+00:00',
            $this->timestamp(Flight::query()->sole(), 'last_observed_at'),
        );
    }

    private function service(
        FakeFlightProvider $provider,
        string $now = '2026-08-31T10:00:00+00:00',
    ): FlightIngestionService {
        return new FlightIngestionService(
            $provider,
            new GateAllocationService(new OccupancyWindowPolicy(90)),
            new FlightObservationStore(),
            new FrozenClock(new DateTimeImmutable($now)),
        );
    }

    private function arrivalQuery(): ArrivalQuery
    {
        return new ArrivalQuery(
            new AirportIcao('EDDF'),
            new DateTimeImmutable('2026-08-30T00:00:00+00:00'),
            new DateTimeImmutable('2026-08-30T02:00:00+00:00'),
        );
    }

    private function flightData(string $externalId): ExternalFlightData
    {
        return new ExternalFlightData(
            provider: 'fake',
            externalId: $externalId,
            airport: new AirportIcao('EDDF'),
            callsign: 'DLH123',
            aircraftIcao24: '3c6444',
            arrivalAt: new DateTimeImmutable('2026-08-30T01:30:00+00:00'),
        );
    }

    private function timestamp(Flight $flight, string $attribute): string
    {
        $value = $flight->getAttribute($attribute);

        $this->assertInstanceOf(DateTimeImmutable::class, $value);

        return $value->format(DATE_ATOM);
    }
}
