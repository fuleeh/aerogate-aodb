<?php

declare(strict_types=1);

namespace App\Contracts\FlightProviders;

interface FlightProvider
{
    /** @return iterable<ExternalFlightData> */
    public function arrivals(ArrivalQuery $query): iterable;
}
