<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Ingestion\FlightIngestionConfiguration;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FlightIngestionConfigurationTest extends TestCase
{
    public function test_it_builds_a_delayed_utc_query_window(): void
    {
        $configuration = FlightIngestionConfiguration::fromArray([
            'airport_icao' => 'eddf',
            'query_window_minutes' => '120',
            'query_delay_minutes' => '1440',
        ]);

        $query = $configuration->arrivalQuery(
            new DateTimeImmutable('2026-08-31 13:00:00 Europe/Bucharest'),
        );

        $this->assertSame('EDDF', $query->airport->value);
        $this->assertSame('2026-08-30T08:00:00+00:00', $query->startsAt->format(DATE_ATOM));
        $this->assertSame('2026-08-30T10:00:00+00:00', $query->endsAt->format(DATE_ATOM));
    }

    public function test_query_window_cannot_exceed_the_provider_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 2880 minutes');

        new FlightIngestionConfiguration(
            airport: new \App\Domain\Flights\AirportIcao('EDDF'),
            queryWindowMinutes: 2_881,
            queryDelayMinutes: 0,
        );
    }
}
