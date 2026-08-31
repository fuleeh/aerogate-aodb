<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Allocations\OccupancyWindowPolicy;
use DateTimeImmutable;
use LogicException;
use Tests\TestCase;

final class OccupancyWindowConfigurationTest extends TestCase
{
    public function test_the_container_builds_the_policy_from_configuration(): void
    {
        config()->set('aerogate.occupancy_duration_minutes', '45');

        $policy = $this->app->make(OccupancyWindowPolicy::class);
        $window = $policy->forFirstObservation(new DateTimeImmutable('2026-08-31T10:00:00+00:00'));

        $this->assertSame('2026-08-31T10:45:00+00:00', $window->endsAt->format(DATE_ATOM));
    }

    public function test_non_integer_configuration_is_rejected(): void
    {
        config()->set('aerogate.occupancy_duration_minutes', 'ninety');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('GATE_OCCUPANCY_DURATION_MINUTES must be a positive integer.');

        $this->app->make(OccupancyWindowPolicy::class);
    }

    public function test_non_positive_configuration_is_rejected(): void
    {
        config()->set('aerogate.occupancy_duration_minutes', '0');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('GATE_OCCUPANCY_DURATION_MINUTES must be a positive integer.');

        $this->app->make(OccupancyWindowPolicy::class);
    }
}
