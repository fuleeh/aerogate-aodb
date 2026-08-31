<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditFlightAllocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_prints_the_same_stable_metric_names_used_by_the_report(): void
    {
        /** @var \Illuminate\Testing\PendingCommand $command */
        $command = $this->artisan('flights:audit');

        $command->expectsOutputToContain('Allocation audit complete. Run ID:')
            ->expectsOutputToContain('flights_pending=0')
            ->expectsOutputToContain('gates_total=0')
            ->expectsOutputToContain('stale_unassigned_flights=0')
            ->expectsOutputToContain('maintenance_conflicts=0')
            ->expectsOutputToContain('anomalies_total=0')
            ->assertSuccessful();
    }

    public function test_the_audit_has_its_own_guarded_schedule(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());
        $audit = $events->first(
            fn (Event $event): bool => str_contains($event->command ?? '', 'flights:audit'),
        );
        $ingestion = $events->first(
            fn (Event $event): bool => str_contains($event->command ?? '', 'flights:fetch-and-allocate'),
        );

        $this->assertInstanceOf(Event::class, $audit);
        $this->assertInstanceOf(Event::class, $ingestion);
        $this->assertNotSame($audit, $ingestion);
        $this->assertSame('*/5 * * * *', $audit->expression);
        $this->assertTrue($audit->withoutOverlapping);
        $this->assertSame(10, $audit->expiresAt);
        $this->assertTrue($audit->onOneServer);
        $this->assertSame('allocation-audit', $audit->description);
    }
}
