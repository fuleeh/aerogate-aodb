<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Allocations\OccupancyWindow;
use App\Domain\Allocations\OccupancyWindowPolicy;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OccupancyWindowPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_builds_the_default_ninety_minute_half_open_window(): void
    {
        CarbonImmutable::setTestNow(new CarbonImmutable('2026-08-31 10:15:00', 'Europe/Bucharest'));
        $firstObservedAt = CarbonImmutable::now(new DateTimeZone('Europe/Bucharest'));

        $window = (new OccupancyWindowPolicy(90))->forFirstObservation($firstObservedAt);

        $this->assertSame('2026-08-31T07:15:00+00:00', $window->startsAt->format(DATE_ATOM));
        $this->assertSame('2026-08-31T08:45:00+00:00', $window->endsAt->format(DATE_ATOM));
        $this->assertSame('Europe/Bucharest', $firstObservedAt->getTimezone()->getName());
    }

    public function test_it_uses_the_supplied_duration(): void
    {
        $firstObservedAt = new DateTimeImmutable('2026-08-31T10:00:00+00:00');

        $window = (new OccupancyWindowPolicy(30))->forFirstObservation($firstObservedAt);

        $this->assertSame('2026-08-31T10:30:00+00:00', $window->endsAt->format(DATE_ATOM));
    }

    public function test_duration_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Gate occupancy duration must be greater than zero minutes.');

        new OccupancyWindowPolicy(0);
    }

    public function test_window_end_must_be_after_its_start(): void
    {
        $instant = new DateTimeImmutable('2026-08-31T10:00:00+00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupancy window start must be before its end.');

        new OccupancyWindow($instant, $instant);
    }
}
