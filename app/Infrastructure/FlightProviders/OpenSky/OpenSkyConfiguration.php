<?php

declare(strict_types=1);

namespace App\Infrastructure\FlightProviders\OpenSky;

use InvalidArgumentException;

final readonly class OpenSkyConfiguration
{
    public function __construct(
        public string $baseUrl,
        public string $tokenUrl,
        public ?string $clientId,
        public ?string $clientSecret,
        public int $connectTimeoutSeconds,
        public int $requestTimeoutSeconds,
        public int $httpAttempts,
        public int $retryDelayMilliseconds,
    ) {
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('OpenSky base URL must be valid.');
        }

        if (filter_var($tokenUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('OpenSky token URL must be valid.');
        }

        if (($clientId === null) !== ($clientSecret === null)) {
            throw new InvalidArgumentException('OpenSky client ID and secret must be configured together.');
        }

        if ($connectTimeoutSeconds <= 0 || $requestTimeoutSeconds <= 0 || $httpAttempts <= 0) {
            throw new InvalidArgumentException('OpenSky timeouts and HTTP attempts must be positive integers.');
        }

        if ($retryDelayMilliseconds < 0) {
            throw new InvalidArgumentException('OpenSky retry delay cannot be negative.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $clientId = self::optionalString($values, 'client_id');
        $clientSecret = self::optionalString($values, 'client_secret');

        return new self(
            baseUrl: self::requiredString($values, 'base_url'),
            tokenUrl: self::requiredString($values, 'token_url'),
            clientId: $clientId,
            clientSecret: $clientSecret,
            connectTimeoutSeconds: self::integer($values, 'connect_timeout_seconds'),
            requestTimeoutSeconds: self::integer($values, 'request_timeout_seconds'),
            httpAttempts: self::integer($values, 'http_attempts'),
            retryDelayMilliseconds: self::integer($values, 'retry_delay_milliseconds'),
        );
    }

    /** @param array<string, mixed> $values */
    private static function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("OpenSky configuration [$key] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("OpenSky configuration [$key] must be a string or null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException("OpenSky configuration [$key] must be an integer.");
    }
}
