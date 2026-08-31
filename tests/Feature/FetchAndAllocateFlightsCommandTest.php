<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\FlightProviders\ExternalFlightData;
use App\Contracts\FlightProviders\FlightProvider;
use App\Domain\Flights\AirportIcao;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Clock\ClockInterface;
use Tests\Fakes\FakeFlightProvider;
use Tests\Fakes\FrozenClock;
use Tests\TestCase;

final class FetchAndAllocateFlightsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_delegates_to_ingestion_and_reports_a_successful_summary(): void
    {
        $provider = FakeFlightProvider::returning();
        $this->bindRuntime($provider);
        Http::preventStrayRequests();

        /** @var \Illuminate\Testing\PendingCommand $command */
        $command = $this->artisan('flights:fetch-and-allocate');

        $command->expectsOutputToContain(
            'Flight ingestion complete: processed=0 created=0 updated=0 allocated=0 unassigned=0 failed=0.',
        )
            ->assertSuccessful();
        unset($command);

        $this->assertCount(1, $provider->receivedQueries());
        $query = $provider->receivedQueries()[0];
        $this->assertSame('EDDF', $query->airport->value);
        $this->assertSame('2026-08-30T08:00:00+00:00', $query->startsAt->format(DATE_ATOM));
        $this->assertSame('2026-08-30T10:00:00+00:00', $query->endsAt->format(DATE_ATOM));
    }

    public function test_capacity_exhaustion_is_a_successful_operational_outcome(): void
    {
        $provider = FakeFlightProvider::returning($this->flightData());
        $this->bindRuntime($provider);

        /** @var \Illuminate\Testing\PendingCommand $command */
        $command = $this->artisan('flights:fetch-and-allocate');

        $command->expectsOutputToContain('processed=1 created=1 updated=0 allocated=0 unassigned=1 failed=0')
            ->assertSuccessful();
    }

    public function test_provider_failure_returns_a_failure_exit_code_without_leaking_details(): void
    {
        $provider = FakeFlightProvider::failing('Sensitive upstream detail.');
        $this->bindRuntime($provider);

        /** @var \Illuminate\Testing\PendingCommand $command */
        $command = $this->artisan('flights:fetch-and-allocate');

        $command->expectsOutputToContain('Flight ingestion failed. Run ID:')
            ->doesntExpectOutputToContain('Sensitive upstream detail.')
            ->assertFailed();
    }

    public function test_the_schedule_guards_against_overlap_across_scheduler_hosts(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'flights:fetch-and-allocate'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(15, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('flight-ingestion', $event->description);
    }

    private function bindRuntime(FakeFlightProvider $provider): void
    {
        config()->set('aerogate.ingestion', [
            'airport_icao' => 'EDDF',
            'query_window_minutes' => 120,
            'query_delay_minutes' => 1_440,
        ]);

        $this->app->instance(FlightProvider::class, $provider);
        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(new DateTimeImmutable('2026-08-31T10:00:00+00:00')),
        );
    }

    private function flightData(): ExternalFlightData
    {
        return new ExternalFlightData(
            provider: 'fake',
            externalId: 'flight-1',
            airport: new AirportIcao('EDDF'),
            callsign: 'DLH123',
            aircraftIcao24: '3c6444',
            arrivalAt: new DateTimeImmutable('2026-08-30T09:30:00+00:00'),
        );
    }
}
