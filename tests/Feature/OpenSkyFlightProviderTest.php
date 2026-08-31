<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\FlightProviders\ArrivalQuery;
use App\Contracts\FlightProviders\FlightProviderException;
use App\Domain\Flights\AirportIcao;
use App\Enums\FlightProviderFailure;
use App\Infrastructure\FlightProviders\OpenSky\OpenSkyConfiguration;
use App\Infrastructure\FlightProviders\OpenSky\OpenSkyFlightProvider;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenSkyFlightProviderTest extends TestCase
{
    public function test_it_fetches_and_normalizes_arrivals_without_credentials(): void
    {
        Http::fake([
            'https://opensky.test/flights/arrival*' => Http::response([$this->validFlight()], 200),
        ]);

        $arrivals = [...$this->provider()->arrivals($this->arrivalQuery())];

        $this->assertCount(1, $arrivals);
        $this->assertSame('opensky', $arrivals[0]->provider);
        $this->assertSame('3c6444:1725091200:1725094800', $arrivals[0]->externalId);
        $this->assertSame('DLH123', $arrivals[0]->callsign);
        $this->assertSame('3c6444', $arrivals[0]->aircraftIcao24);
        $this->assertSame('2024-08-31T09:00:00+00:00', $arrivals[0]->arrivalAt?->format(DATE_ATOM));

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://opensky.test/flights/arrival?airport=EDDF&begin=1725087600&end=1725094800'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_it_uses_oauth_client_credentials_and_reuses_the_token(): void
    {
        Http::fake([
            'https://auth.opensky.test/token' => Http::response([
                'access_token' => 'access-token',
                'expires_in' => 300,
            ]),
            'https://opensky.test/flights/arrival*' => Http::response([], 200),
        ]);
        $provider = $this->provider(clientId: 'client-id', clientSecret: 'client-secret');

        [...$provider->arrivals($this->arrivalQuery())];
        [...$provider->arrivals($this->arrivalQuery())];

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.opensky.test/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret');
        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://opensky.test/flights/arrival',
        ) && $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function test_official_not_found_response_is_an_empty_success(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $arrivals = [...$this->provider()->arrivals($this->arrivalQuery())];

        $this->assertSame([], $arrivals);
    }

    public function test_rate_limiting_is_retried_and_classified_when_exhausted(): void
    {
        Http::fakeSequence()
            ->push([], 429)
            ->push([], 429)
            ->push([], 429);

        try {
            [...$this->provider(attempts: 3)->arrivals($this->arrivalQuery())];
            $this->fail('Expected rate limiting to fail after bounded attempts.');
        } catch (FlightProviderException $exception) {
            $this->assertSame(FlightProviderFailure::RateLimited, $exception->reason);
        }

        Http::assertSentCount(3);
    }

    public function test_transient_server_failure_is_retried(): void
    {
        Http::fakeSequence()
            ->push([], 503)
            ->push([$this->validFlight()], 200);

        $arrivals = [...$this->provider(attempts: 2)->arrivals($this->arrivalQuery())];

        $this->assertCount(1, $arrivals);
        Http::assertSentCount(2);
    }

    public function test_connection_failure_is_retried_and_classified(): void
    {
        Http::fakeSequence()
            ->pushFailedConnection('connection refused')
            ->pushFailedConnection('connection refused');

        $exception = $this->captureFailure(
            fn (): iterable => $this->provider(attempts: 2)->arrivals($this->arrivalQuery()),
        );

        $this->assertSame(FlightProviderFailure::Unavailable, $exception->reason);
        Http::assertSentCount(2);
    }

    public function test_timeout_is_retried_and_classified_deterministically(): void
    {
        Http::fakeSequence()
            ->pushFailedConnection('Operation timed out')
            ->pushFailedConnection('Operation timed out');

        $exception = $this->captureFailure(
            fn (): iterable => $this->provider(attempts: 2)->arrivals($this->arrivalQuery()),
        );

        $this->assertSame(FlightProviderFailure::Unavailable, $exception->reason);
        Http::assertSentCount(2);
    }

    public function test_malformed_batch_is_classified(): void
    {
        Http::fake(['*' => Http::response(['flights' => []], 200)]);

        $exception = $this->captureFailure(fn (): iterable => $this->provider()->arrivals($this->arrivalQuery()));

        $this->assertSame(FlightProviderFailure::MalformedBatch, $exception->reason);
        $this->assertNull($exception->itemIndex);
    }

    public function test_malformed_item_is_classified_with_its_index(): void
    {
        Http::fake(['*' => Http::response([$this->validFlight(), ['icao24' => 'invalid']], 200)]);

        $exception = $this->captureFailure(fn (): iterable => $this->provider()->arrivals($this->arrivalQuery()));

        $this->assertSame(FlightProviderFailure::MalformedItem, $exception->reason);
        $this->assertSame(1, $exception->itemIndex);
    }

    public function test_queries_longer_than_two_days_are_rejected_before_http(): void
    {
        Http::fake();
        $query = new ArrivalQuery(
            new AirportIcao('EDDF'),
            new DateTimeImmutable('2024-08-28T00:00:00+00:00'),
            new DateTimeImmutable('2024-08-31T00:00:01+00:00'),
        );

        $exception = $this->captureFailure(fn (): iterable => $this->provider()->arrivals($query));

        $this->assertSame(FlightProviderFailure::InvalidQuery, $exception->reason);
        Http::assertNothingSent();
    }

    public function test_authentication_failure_is_classified(): void
    {
        Http::fake(['https://auth.opensky.test/token' => Http::response([], 401)]);

        $exception = $this->captureFailure(
            fn (): iterable => $this->provider('client-id', 'wrong-secret')->arrivals($this->arrivalQuery()),
        );

        $this->assertSame(FlightProviderFailure::Authentication, $exception->reason);
    }

    /** @param callable(): iterable<mixed> $operation */
    private function captureFailure(callable $operation): FlightProviderException
    {
        try {
            [...$operation()];
            $this->fail('Expected the OpenSky provider to fail.');
        } catch (FlightProviderException $exception) {
            return $exception;
        }
    }

    private function provider(
        ?string $clientId = null,
        ?string $clientSecret = null,
        int $attempts = 1,
    ): OpenSkyFlightProvider {
        return new OpenSkyFlightProvider(new OpenSkyConfiguration(
            baseUrl: 'https://opensky.test',
            tokenUrl: 'https://auth.opensky.test/token',
            clientId: $clientId,
            clientSecret: $clientSecret,
            connectTimeoutSeconds: 1,
            requestTimeoutSeconds: 2,
            httpAttempts: $attempts,
            retryDelayMilliseconds: 0,
        ));
    }

    private function arrivalQuery(): ArrivalQuery
    {
        return new ArrivalQuery(
            new AirportIcao('EDDF'),
            new DateTimeImmutable('@1725087600'),
            new DateTimeImmutable('@1725094800'),
        );
    }

    /** @return array<string, int|string|null> */
    private function validFlight(): array
    {
        return [
            'icao24' => ' 3C6444 ',
            'firstSeen' => 1_725_091_200,
            'estDepartureAirport' => 'EDDM',
            'lastSeen' => 1_725_094_800,
            'estArrivalAirport' => 'EDDF',
            'callsign' => ' dlh123 ',
        ];
    }
}
