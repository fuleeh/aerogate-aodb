<?php

declare(strict_types=1);

namespace App\Application\Ingestion;

use App\Application\Allocations\GateAllocationService;
use App\Contracts\FlightProviders\ArrivalQuery;
use App\Contracts\FlightProviders\ExternalFlightData;
use App\Contracts\FlightProviders\FlightProvider;
use App\Domain\Ingestion\FlightIngestionFailure;
use App\Domain\Ingestion\FlightIngestionSummary;
use App\Enums\GateAllocationOutcome;
use App\Infrastructure\Persistence\FlightObservationStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class FlightIngestionService
{
    public function __construct(
        private FlightProvider $flightProvider,
        private GateAllocationService $gateAllocationService,
        private FlightObservationStore $flightObservationStore,
        private ClockInterface $clock,
    ) {
    }

    public function ingest(ArrivalQuery $query): FlightIngestionSummary
    {
        $observedAt = DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new DateTimeZone('UTC'));
        $created = 0;
        $updated = 0;
        $allocated = 0;
        $unassigned = 0;
        $failures = [];

        foreach ($this->flightProvider->arrivals($query) as $data) {
            try {
                [$wasCreated, $allocationOutcome] = $this->processItem($data, $observedAt);

                $wasCreated ? $created++ : $updated++;

                if ($allocationOutcome === GateAllocationOutcome::NoGateAvailable) {
                    $unassigned++;
                } else {
                    $allocated++;
                }
            } catch (Throwable $exception) {
                $failures[] = new FlightIngestionFailure(
                    $data->provider,
                    $data->externalId,
                    $exception,
                );
            }
        }

        return new FlightIngestionSummary($created, $updated, $allocated, $unassigned, $failures);
    }

    /** @return array{bool, GateAllocationOutcome} */
    private function processItem(ExternalFlightData $data, DateTimeImmutable $observedAt): array
    {
        return DB::transaction(function () use ($data, $observedAt): array {
            $observation = $this->flightObservationStore->persist($data, $observedAt);
            $allocation = $this->gateAllocationService->allocate($observation->flight);

            return [$observation->wasCreated, $allocation->outcome];
        }, attempts: 3);
    }
}
