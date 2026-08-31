<?php

declare(strict_types=1);

namespace App\Infrastructure\FlightProviders\OpenSky;

use App\Contracts\FlightProviders\ArrivalQuery;
use App\Contracts\FlightProviders\ExternalFlightData;
use App\Contracts\FlightProviders\FlightProvider;
use App\Contracts\FlightProviders\FlightProviderException;
use App\Enums\FlightProviderFailure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class OpenSkyFlightProvider implements FlightProvider
{
    private const int MAX_QUERY_SECONDS = 172_800;

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    public function __construct(private readonly OpenSkyConfiguration $configuration)
    {
    }

    public function arrivals(ArrivalQuery $query): iterable
    {
        if (($query->endsAt->getTimestamp() - $query->startsAt->getTimestamp()) > self::MAX_QUERY_SECONDS) {
            throw new FlightProviderException(
                FlightProviderFailure::InvalidQuery,
                'OpenSky arrival queries cannot cover more than two days.',
            );
        }

        try {
            $request = $this->request();
            $token = $this->accessToken();

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            $response = $request->get(
                rtrim($this->configuration->baseUrl, '/').'/flights/arrival',
                [
                    'airport' => $query->airport->value,
                    'begin' => $query->startsAt->getTimestamp(),
                    'end' => $query->endsAt->getTimestamp(),
                ],
            );
        } catch (ConnectionException $exception) {
            throw new FlightProviderException(
                FlightProviderFailure::Unavailable,
                'OpenSky could not be reached.',
                previous: $exception,
            );
        }

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw $this->responseException($response, authenticationRequest: false);
        }

        $payload = $response->json();

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new FlightProviderException(
                FlightProviderFailure::MalformedBatch,
                'OpenSky returned a malformed arrivals batch.',
            );
        }

        $arrivals = [];

        foreach ($payload as $index => $item) {
            try {
                $arrivals[] = $this->mapFlight($item, $query);
            } catch (InvalidArgumentException $exception) {
                throw new FlightProviderException(
                    FlightProviderFailure::MalformedItem,
                    "OpenSky returned a malformed flight at index $index.",
                    itemIndex: $index,
                    previous: $exception,
                );
            }
        }

        return $arrivals;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout($this->configuration->connectTimeoutSeconds)
            ->timeout($this->configuration->requestTimeoutSeconds)
            ->retry(
                $this->configuration->httpAttempts,
                fn (int $attempt): int => $this->configuration->retryDelayMilliseconds * (2 ** ($attempt - 1)),
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (! $exception instanceof RequestException || $exception->response === null) {
                        return false;
                    }

                    return $exception->response->status() === 429 || $exception->response->serverError();
                },
                throw: false,
            );
    }

    private function accessToken(): ?string
    {
        if ($this->configuration->clientId === null || $this->configuration->clientSecret === null) {
            return null;
        }

        if ($this->accessToken !== null && $this->accessTokenExpiresAt > time()) {
            return $this->accessToken;
        }

        try {
            $response = $this->request()
                ->asForm()
                ->post($this->configuration->tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->configuration->clientId,
                    'client_secret' => $this->configuration->clientSecret,
                ]);
        } catch (ConnectionException $exception) {
            throw new FlightProviderException(
                FlightProviderFailure::Unavailable,
                'OpenSky authentication service could not be reached.',
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw $this->responseException($response, authenticationRequest: true);
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! is_string($token) || $token === '' || ! is_int($expiresIn) || $expiresIn <= 0) {
            throw new FlightProviderException(
                FlightProviderFailure::MalformedBatch,
                'OpenSky returned a malformed authentication response.',
            );
        }

        $this->accessToken = $token;
        $this->accessTokenExpiresAt = time() + max(1, $expiresIn - 30);

        return $this->accessToken;
    }

    private function responseException(Response $response, bool $authenticationRequest): FlightProviderException
    {
        $status = $response->status();

        if ($status === 429) {
            return new FlightProviderException(FlightProviderFailure::RateLimited, 'OpenSky rate limit exceeded.');
        }

        if ($authenticationRequest || in_array($status, [401, 403], true)) {
            return new FlightProviderException(
                FlightProviderFailure::Authentication,
                'OpenSky authentication failed.',
            );
        }

        if (in_array($status, [400, 422], true)) {
            return new FlightProviderException(FlightProviderFailure::InvalidQuery, 'OpenSky rejected the query.');
        }

        return new FlightProviderException(
            FlightProviderFailure::Unavailable,
            "OpenSky request failed with HTTP status $status.",
        );
    }

    private function mapFlight(mixed $item, ArrivalQuery $query): ExternalFlightData
    {
        if (! is_array($item)) {
            throw new InvalidArgumentException('Flight item must be an object.');
        }

        $icao24 = $item['icao24'] ?? null;
        $firstSeen = $item['firstSeen'] ?? null;
        $lastSeen = $item['lastSeen'] ?? null;
        $callsign = $item['callsign'] ?? null;

        if (! is_string($icao24) || ! is_int($firstSeen) || ! is_int($lastSeen)) {
            throw new InvalidArgumentException('Flight identity fields are missing or invalid.');
        }

        if ($firstSeen <= 0 || $lastSeen < $firstSeen) {
            throw new InvalidArgumentException('Flight timestamps are invalid.');
        }

        if ($callsign !== null && ! is_string($callsign)) {
            throw new InvalidArgumentException('Flight callsign is invalid.');
        }

        $normalizedIcao24 = strtolower(trim($icao24));
        $normalizedCallsign = $callsign === null ? null : strtoupper(trim($callsign));
        $normalizedCallsign = $normalizedCallsign === '' ? null : $normalizedCallsign;

        return new ExternalFlightData(
            provider: 'opensky',
            externalId: "$normalizedIcao24:$firstSeen:$lastSeen",
            airport: $query->airport,
            callsign: $normalizedCallsign,
            aircraftIcao24: $normalizedIcao24,
            arrivalAt: (new DateTimeImmutable("@$lastSeen")),
        );
    }
}
