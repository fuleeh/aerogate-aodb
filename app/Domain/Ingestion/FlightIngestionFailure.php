<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use Throwable;

final readonly class FlightIngestionFailure
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public Throwable $exception,
    ) {
    }
}
