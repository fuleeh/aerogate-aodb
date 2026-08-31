<?php

declare(strict_types=1);

namespace App\Domain\Flights;

use InvalidArgumentException;
use Stringable;

final readonly class AirportIcao implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $normalizedValue = strtoupper(trim($value));

        if (preg_match('/^[A-Z0-9]{4}$/', $normalizedValue) !== 1) {
            throw new InvalidArgumentException('An airport ICAO code must contain exactly four letters or digits.');
        }

        $this->value = $normalizedValue;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
