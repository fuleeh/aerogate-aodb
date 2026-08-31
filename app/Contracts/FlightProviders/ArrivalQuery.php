<?php

declare(strict_types=1);

namespace App\Contracts\FlightProviders;

use App\Domain\Flights\AirportIcao;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ArrivalQuery
{
    public DateTimeImmutable $startsAt;

    public DateTimeImmutable $endsAt;

    public function __construct(
        public AirportIcao $airport,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->startsAt = DateTimeImmutable::createFromInterface($startsAt)->setTimezone($utc);
        $this->endsAt = DateTimeImmutable::createFromInterface($endsAt)->setTimezone($utc);

        if ($this->startsAt >= $this->endsAt) {
            throw new InvalidArgumentException('Arrival query start must be before its end.');
        }
    }
}
