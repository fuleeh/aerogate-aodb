<?php

declare(strict_types=1);

namespace App\Domain\Allocations;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OccupancyWindow
{
    public function __construct(
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {
        if ($startsAt >= $endsAt) {
            throw new InvalidArgumentException('Occupancy window start must be before its end.');
        }
    }
}
