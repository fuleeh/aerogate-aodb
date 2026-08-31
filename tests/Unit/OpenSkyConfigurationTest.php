<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\FlightProviders\OpenSky\OpenSkyConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpenSkyConfigurationTest extends TestCase
{
    public function test_environment_style_integer_strings_are_normalized(): void
    {
        $configuration = OpenSkyConfiguration::fromArray([
            'base_url' => 'https://opensky.test',
            'token_url' => 'https://auth.opensky.test/token',
            'client_id' => null,
            'client_secret' => null,
            'connect_timeout_seconds' => '5',
            'request_timeout_seconds' => '15',
            'http_attempts' => '3',
            'retry_delay_milliseconds' => '250',
        ]);

        $this->assertSame(5, $configuration->connectTimeoutSeconds);
        $this->assertSame(15, $configuration->requestTimeoutSeconds);
        $this->assertSame(3, $configuration->httpAttempts);
        $this->assertSame(250, $configuration->retryDelayMilliseconds);
    }

    public function test_partial_oauth_credentials_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client ID and secret must be configured together');

        new OpenSkyConfiguration(
            baseUrl: 'https://opensky.test',
            tokenUrl: 'https://auth.opensky.test/token',
            clientId: 'client-id',
            clientSecret: null,
            connectTimeoutSeconds: 5,
            requestTimeoutSeconds: 15,
            httpAttempts: 3,
            retryDelayMilliseconds: 250,
        );
    }
}
