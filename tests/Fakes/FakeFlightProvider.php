<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\FlightProviders\ArrivalQuery;
use App\Contracts\FlightProviders\ExternalFlightData;
use App\Contracts\FlightProviders\FlightProvider;
use App\Contracts\FlightProviders\FlightProviderException;
use App\Enums\FlightProviderFailure;

final class FakeFlightProvider implements FlightProvider
{
    /** @var list<ArrivalQuery> */
    private array $receivedQueries = [];

    /**
     * @param list<ExternalFlightData> $arrivals
     */
    private function __construct(
        private readonly array $arrivals,
        private readonly ?FlightProviderException $failure,
    ) {
    }

    public static function returning(ExternalFlightData ...$arrivals): self
    {
        return new self(array_values($arrivals), null);
    }

    public static function failing(string $message = 'Flight provider request failed.'): self
    {
        return new self([], new FlightProviderException(FlightProviderFailure::Unavailable, $message));
    }

    public function arrivals(ArrivalQuery $query): iterable
    {
        $this->receivedQueries[] = $query;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        yield from $this->arrivals;
    }

    /** @return list<ArrivalQuery> */
    public function receivedQueries(): array
    {
        return $this->receivedQueries;
    }
}
