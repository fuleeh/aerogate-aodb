<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Models\Flight;

final readonly class FlightObservationWrite
{
    public function __construct(
        public Flight $flight,
        public bool $wasCreated,
    ) {
    }
}
