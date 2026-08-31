<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use InvalidArgumentException;

final readonly class FlightIngestionSummary
{
    public int $failed;

    /**
     * @param list<FlightIngestionFailure> $failures
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $allocated,
        public int $unassigned,
        public array $failures,
    ) {
        if (min($created, $updated, $allocated, $unassigned) < 0) {
            throw new InvalidArgumentException('Flight ingestion counts cannot be negative.');
        }

        if (($created + $updated) !== ($allocated + $unassigned)) {
            throw new InvalidArgumentException('Every persisted flight must have an allocation outcome.');
        }

        $this->failed = count($failures);
    }

    public function processed(): int
    {
        return $this->created + $this->updated + $this->failed;
    }
}
