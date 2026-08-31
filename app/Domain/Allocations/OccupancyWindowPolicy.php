<?php

declare(strict_types=1);

namespace App\Domain\Allocations;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OccupancyWindowPolicy
{
    public function __construct(private int $durationMinutes)
    {
        if ($durationMinutes <= 0) {
            throw new InvalidArgumentException('Gate occupancy duration must be greater than zero minutes.');
        }
    }

    public function forFirstObservation(DateTimeImmutable $firstObservedAt): OccupancyWindow
    {
        $startsAt = DateTimeImmutable::createFromInterface($firstObservedAt)
            ->setTimezone(new DateTimeZone('UTC'));
        $endsAt = $startsAt->add(new DateInterval(sprintf('PT%dM', $this->durationMinutes)));

        return new OccupancyWindow($startsAt, $endsAt);
    }
}
