<?php

declare(strict_types=1);

namespace App\Application\Ingestion;

use App\Contracts\FlightProviders\ArrivalQuery;
use App\Domain\Flights\AirportIcao;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class FlightIngestionConfiguration
{
    private const int MAX_QUERY_WINDOW_MINUTES = 2_880;

    public function __construct(
        public AirportIcao $airport,
        public int $queryWindowMinutes,
        public int $queryDelayMinutes,
    ) {
        if ($queryWindowMinutes <= 0 || $queryWindowMinutes > self::MAX_QUERY_WINDOW_MINUTES) {
            throw new InvalidArgumentException('Flight query window must contain between 1 and 2880 minutes.');
        }

        if ($queryDelayMinutes < 0) {
            throw new InvalidArgumentException('Flight query delay cannot be negative.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $airport = $values['airport_icao'] ?? null;

        if (! is_string($airport)) {
            throw new InvalidArgumentException('AIRPORT_ICAO must be a string.');
        }

        return new self(
            new AirportIcao($airport),
            self::integer($values, 'query_window_minutes'),
            self::integer($values, 'query_delay_minutes'),
        );
    }

    public function arrivalQuery(DateTimeImmutable $now): ArrivalQuery
    {
        $utcNow = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
        $endsAt = $utcNow->sub(new DateInterval("PT{$this->queryDelayMinutes}M"));
        $startsAt = $endsAt->sub(new DateInterval("PT{$this->queryWindowMinutes}M"));

        return new ArrivalQuery($this->airport, $startsAt, $endsAt);
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException("Flight ingestion configuration [$key] must be an integer.");
    }
}
