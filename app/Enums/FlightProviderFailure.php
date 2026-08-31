<?php

declare(strict_types=1);

namespace App\Enums;

enum FlightProviderFailure: string
{
    case Authentication = 'authentication';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case InvalidQuery = 'invalid_query';
    case MalformedBatch = 'malformed_batch';
    case MalformedItem = 'malformed_item';
}
