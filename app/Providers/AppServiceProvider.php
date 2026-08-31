<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Auditing\AllocationAuditConfiguration;
use App\Application\Ingestion\FlightIngestionConfiguration;
use App\Contracts\FlightProviders\FlightProvider;
use App\Domain\Allocations\OccupancyWindowPolicy;
use App\Infrastructure\FlightProviders\OpenSky\OpenSkyConfiguration;
use App\Infrastructure\FlightProviders\OpenSky\OpenSkyFlightProvider;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Psr\Clock\ClockInterface;
use App\Infrastructure\Time\SystemClock;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, SystemClock::class);

        $this->app->singleton(
            AllocationAuditConfiguration::class,
            static function (): AllocationAuditConfiguration {
                $configuration = config('aerogate.audit');

                if (! is_array($configuration)) {
                    throw new LogicException('Allocation audit configuration is missing.');
                }

                return AllocationAuditConfiguration::fromArray($configuration);
            },
        );

        $this->app->singleton(
            FlightIngestionConfiguration::class,
            static function (): FlightIngestionConfiguration {
                $configuration = config('aerogate.ingestion');

                if (! is_array($configuration)) {
                    throw new LogicException('Flight ingestion configuration is missing.');
                }

                return FlightIngestionConfiguration::fromArray($configuration);
            },
        );

        $this->app->singleton(OpenSkyConfiguration::class, static function (): OpenSkyConfiguration {
            $configuration = config('services.opensky');

            if (! is_array($configuration)) {
                throw new LogicException('OpenSky configuration is missing.');
            }

            return OpenSkyConfiguration::fromArray($configuration);
        });

        $this->app->singleton(FlightProvider::class, OpenSkyFlightProvider::class);

        $this->app->singleton(
            OccupancyWindowPolicy::class,
            static function (): OccupancyWindowPolicy {
                $configuredDuration = config('aerogate.occupancy_duration_minutes');

                if (is_int($configuredDuration)) {
                    $durationMinutes = $configuredDuration;
                } elseif (is_string($configuredDuration) && ctype_digit($configuredDuration)) {
                    $durationMinutes = (int) $configuredDuration;
                } else {
                    throw new LogicException('GATE_OCCUPANCY_DURATION_MINUTES must be a positive integer.');
                }

                if ($durationMinutes <= 0) {
                    throw new LogicException('GATE_OCCUPANCY_DURATION_MINUTES must be a positive integer.');
                }

                return new OccupancyWindowPolicy($durationMinutes);
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
