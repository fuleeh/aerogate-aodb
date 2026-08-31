<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Allocations\GateAllocationService;
use App\Enums\GateAllocationOutcome;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class ConcurrentGateAllocationTest extends TestCase
{
    use DatabaseMigrations;

    private const int EXIT_ALLOCATED = 10;

    private const int EXIT_ALREADY_ALLOCATED = 11;

    private const int EXIT_NO_GATE = 12;

    private const int EXIT_FAILURE = 90;

    public function test_competing_processes_cannot_double_book_one_gate(): void
    {
        Gate::factory()->create();
        $firstFlight = $this->flight();
        $secondFlight = $this->flight();

        $exitCodes = $this->allocateConcurrently([$firstFlight->id, $secondFlight->id]);

        sort($exitCodes);
        $this->assertSame([self::EXIT_ALLOCATED, self::EXIT_NO_GATE], $exitCodes);
        $this->assertDatabaseCount('gate_allocations', 1);
        $this->assertSame(1, GateAllocation::query()->distinct()->count('gate_id'));
    }

    public function test_duplicate_processes_converge_on_one_allocation(): void
    {
        Gate::factory()->create();
        $flight = $this->flight();

        $exitCodes = $this->allocateConcurrently([$flight->id, $flight->id]);

        sort($exitCodes);
        $this->assertSame([self::EXIT_ALLOCATED, self::EXIT_ALREADY_ALLOCATED], $exitCodes);
        $this->assertDatabaseCount('gate_allocations', 1);
    }

    /**
     * @param list<int> $flightIds
     * @return list<int>
     */
    private function allocateConcurrently(array $flightIds): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('The Docker verification environment requires the pcntl extension.');
        }

        DB::disconnect();

        /** @var list<array{pid: int, signal: resource}> $children */
        $children = [];

        foreach ($flightIds as $flightId) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($sockets === false) {
                throw new RuntimeException('Could not create the process synchronization socket.');
            }

            [$parentSocket, $childSocket] = $sockets;
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not fork an allocation test process.');
            }

            if ($pid === 0) {
                fclose($parentSocket);
                DB::purge();
                fread($childSocket, 1);
                fclose($childSocket);

                try {
                    $flight = Flight::query()->findOrFail($flightId);
                    $result = $this->app->make(GateAllocationService::class)->allocate($flight);

                    exit(match ($result->outcome) {
                        GateAllocationOutcome::Allocated => self::EXIT_ALLOCATED,
                        GateAllocationOutcome::AlreadyAllocated => self::EXIT_ALREADY_ALLOCATED,
                        GateAllocationOutcome::NoGateAvailable => self::EXIT_NO_GATE,
                    });
                } catch (Throwable) {
                    exit(self::EXIT_FAILURE);
                }
            }

            fclose($childSocket);
            $children[] = ['pid' => $pid, 'signal' => $parentSocket];
        }

        foreach ($children as $child) {
            fwrite($child['signal'], '1');
            fclose($child['signal']);
        }

        $exitCodes = [];

        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child['pid'], $status);
            $exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : false;
            $exitCodes[] = is_int($exitCode) ? $exitCode : self::EXIT_FAILURE;
        }

        DB::purge();

        return $exitCodes;
    }

    private function flight(): Flight
    {
        $observedAt = CarbonImmutable::parse('2026-08-31 10:00:00 UTC');

        return Flight::factory()->create([
            'first_observed_at' => $observedAt,
            'last_observed_at' => $observedAt,
        ]);
    }
}
