<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Allocations\OccupancyWindowPolicy;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
