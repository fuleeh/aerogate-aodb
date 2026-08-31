<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\FlightProviders\ArrivalQuery;
use App\Contracts\FlightProviders\ExternalFlightData;
use App\Contracts\FlightProviders\FlightProviderException;
use App\Domain\Flights\AirportIcao;
use App\Enums\FlightProviderFailure;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeFlightProvider;

final class FlightProviderContractTest extends TestCase
{
    public function test_the_fake_returns_normalized_arrivals_in_order_and_records_the_query(): void
    {
        $query = $this->query();
        $first = $this->flight('flight-1');
        $second = $this->flight('flight-2');
        $provider = FakeFlightProvider::returning($first, $second);

        $arrivals = iterator_to_array($provider->arrivals($query));

        $this->assertSame([$first, $second], $arrivals);
        $this->assertSame([$query], $provider->receivedQueries());
    }

    public function test_an_empty_success_is_not_a_failure(): void
    {
        $provider = FakeFlightProvider::returning();

        $this->assertSame([], iterator_to_array($provider->arrivals($this->query())));
    }

    public function test_a_provider_failure_is_thrown_instead_of_returning_an_empty_result(): void
    {
        $provider = FakeFlightProvider::failing('OpenSky unavailable.');

        try {
            iterator_to_array($provider->arrivals($this->query()));
            $this->fail('Expected the fake provider to fail.');
        } catch (FlightProviderException $exception) {
            $this->assertSame(FlightProviderFailure::Unavailable, $exception->reason);
            $this->assertSame('OpenSky unavailable.', $exception->getMessage());
        }
    }

    public function test_airport_codes_are_normalized(): void
    {
        $airport = new AirportIcao(' eddf ');

        $this->assertSame('EDDF', $airport->value);
        $this->assertSame('EDDF', (string) $airport);
    }

    #[DataProvider('invalidAirportCodes')]
    public function test_invalid_airport_codes_are_rejected(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AirportIcao($code);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAirportCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['EDF'];
        yield 'too long' => ['EDDF1'];
        yield 'punctuation' => ['EDD-'];
    }

    public function test_query_timestamps_are_normalized_to_utc(): void
    {
        $query = new ArrivalQuery(
            new AirportIcao('EDDF'),
            new DateTimeImmutable('2026-08-31 10:00:00 Europe/Bucharest'),
            new DateTimeImmutable('2026-08-31 12:00:00 Europe/Bucharest'),
        );

        $this->assertSame('2026-08-31T07:00:00+00:00', $query->startsAt->format(DATE_ATOM));
        $this->assertSame('2026-08-31T09:00:00+00:00', $query->endsAt->format(DATE_ATOM));
    }

    public function test_query_start_must_be_before_its_end(): void
    {
        $instant = new DateTimeImmutable('2026-08-31T10:00:00+00:00');

        $this->expectException(InvalidArgumentException::class);

        new ArrivalQuery(new AirportIcao('EDDF'), $instant, $instant);
    }

    public function test_invalid_normalized_flight_data_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Aircraft ICAO24');

        new ExternalFlightData(
            provider: 'opensky',
            externalId: 'flight-1',
            airport: new AirportIcao('EDDF'),
            callsign: 'DLH123',
            aircraftIcao24: 'NOT-HEX',
            arrivalAt: null,
        );
    }

    public function test_flight_arrival_time_is_normalized_to_utc(): void
    {
        $flight = $this->flight('flight-1');

        $this->assertNotNull($flight->arrivalAt);
        $this->assertSame('2026-08-31T08:30:00+00:00', $flight->arrivalAt->format(DATE_ATOM));
    }

    private function query(): ArrivalQuery
    {
        return new ArrivalQuery(
            new AirportIcao('EDDF'),
            new DateTimeImmutable('2026-08-31T08:00:00+00:00'),
            new DateTimeImmutable('2026-08-31T10:00:00+00:00'),
        );
    }

    private function flight(string $externalId): ExternalFlightData
    {
        return new ExternalFlightData(
            provider: 'opensky',
            externalId: $externalId,
            airport: new AirportIcao('EDDF'),
            callsign: 'DLH123',
            aircraftIcao24: '3c6444',
            arrivalAt: new DateTimeImmutable('2026-08-31 11:30:00 Europe/Bucharest'),
        );
    }
}
