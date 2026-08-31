<?php

declare(strict_types=1);

namespace App\Contracts\FlightProviders;

use App\Domain\Flights\AirportIcao;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ExternalFlightData
{
    public ?DateTimeImmutable $arrivalAt;

    public function __construct(
        public string $provider,
        public string $externalId,
        public AirportIcao $airport,
        public ?string $callsign,
        public ?string $aircraftIcao24,
        ?DateTimeImmutable $arrivalAt,
    ) {
        if ($provider === '' || trim($provider) !== $provider || strlen($provider) > 32) {
            throw new InvalidArgumentException('Flight provider must contain between 1 and 32 characters.');
        }

        if ($externalId === '' || trim($externalId) !== $externalId || strlen($externalId) > 255) {
            throw new InvalidArgumentException('External flight ID must contain between 1 and 255 characters.');
        }

        if ($callsign !== null && ($callsign === '' || trim($callsign) !== $callsign || strlen($callsign) > 16)) {
            throw new InvalidArgumentException('Callsign must be null or contain between 1 and 16 characters.');
        }

        if ($aircraftIcao24 !== null && preg_match('/^[a-f0-9]{6}$/', $aircraftIcao24) !== 1) {
            throw new InvalidArgumentException('Aircraft ICAO24 must be null or six lowercase hexadecimal characters.');
        }

        $this->arrivalAt = $arrivalAt === null
            ? null
            : DateTimeImmutable::createFromInterface($arrivalAt)->setTimezone(new DateTimeZone('UTC'));
    }
}
