<?php

declare(strict_types=1);

namespace App\Contracts\FlightProviders;

use App\Enums\FlightProviderFailure;
use RuntimeException;
use Throwable;

class FlightProviderException extends RuntimeException
{
    public function __construct(
        public readonly FlightProviderFailure $reason,
        string $message,
        public readonly ?int $itemIndex = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
