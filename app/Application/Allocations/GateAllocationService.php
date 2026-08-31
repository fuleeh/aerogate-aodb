<?php

declare(strict_types=1);

namespace App\Application\Allocations;

use App\Domain\Allocations\GateAllocationResult;
use App\Domain\Allocations\OccupancyWindowPolicy;
use App\Enums\FlightAllocationStatus;
use App\Enums\GateAllocationStatus;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final readonly class GateAllocationService
{
    private const int MAX_EXCLUSION_CONFLICT_ATTEMPTS = 3;

    public function __construct(private OccupancyWindowPolicy $occupancyWindowPolicy)
    {
    }

    public function allocate(Flight $flight): GateAllocationResult
    {
        $flightId = $flight->getKey();

        if (! is_int($flightId)) {
            throw new InvalidArgumentException('A flight must be persisted before gate allocation.');
        }

        for ($attempt = 1; $attempt <= self::MAX_EXCLUSION_CONFLICT_ATTEMPTS; $attempt++) {
            try {
                return $this->allocateOnce($flightId);
            } catch (QueryException $exception) {
                if (! $this->isExclusionViolation($exception) || $attempt === self::MAX_EXCLUSION_CONFLICT_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Gate allocation exhausted its bounded retry loop.');
    }

    private function allocateOnce(int $flightId): GateAllocationResult
    {
        return DB::transaction(function () use ($flightId): GateAllocationResult {
            $flight = Flight::query()->lockForUpdate()->findOrFail($flightId);
            $activeAllocation = $flight->activeAllocation()->first();

            if ($activeAllocation !== null) {
                if ($flight->allocation_status !== FlightAllocationStatus::Allocated) {
                    $flight->update(['allocation_status' => FlightAllocationStatus::Allocated]);
                }

                return GateAllocationResult::alreadyAllocated($activeAllocation);
            }

            $firstObservedAt = $flight->first_observed_at;

            if (! $firstObservedAt instanceof DateTimeImmutable) {
                throw new RuntimeException('The flight first observation timestamp is unavailable.');
            }

            $window = $this->occupancyWindowPolicy->forFirstObservation($firstObservedAt);
            $gate = Gate::query()
                ->availableDuring($window->startsAt, $window->endsAt)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($gate === null) {
                $flight->update(['allocation_status' => FlightAllocationStatus::Unassigned]);

                return GateAllocationResult::noGateAvailable();
            }

            $allocation = GateAllocation::query()->create([
                'flight_id' => $flight->id,
                'gate_id' => $gate->id,
                'starts_at' => $window->startsAt,
                'ends_at' => $window->endsAt,
                'status' => GateAllocationStatus::Active,
            ]);

            $flight->update(['allocation_status' => FlightAllocationStatus::Allocated]);

            return GateAllocationResult::allocated($allocation);
        }, attempts: 3);
    }

    private function isExclusionViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? (string) $exception->getCode()) === '23P01';
    }
}
