<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Allocations\GateAllocationService;
use App\Models\Flight;
use App\Models\Gate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GateAllocationRetryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_exclusion_conflict_retry_is_bounded_to_three_attempts(): void
    {
        Gate::factory()->create();
        $observedAt = CarbonImmutable::parse('2026-08-31 10:00:00 UTC');
        $flight = Flight::factory()->create([
            'first_observed_at' => $observedAt,
            'last_observed_at' => $observedAt,
        ]);

        $this->dropRetryProbe();

        try {
            DB::statement('CREATE SEQUENCE allocation_retry_probe');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION force_allocation_exclusion_conflict()
                RETURNS trigger AS $$
                BEGIN
                    PERFORM nextval('allocation_retry_probe');
                    RAISE EXCEPTION 'forced exclusion conflict' USING ERRCODE = '23P01';
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER force_allocation_exclusion_conflict
                BEFORE INSERT ON gate_allocations
                FOR EACH ROW EXECUTE FUNCTION force_allocation_exclusion_conflict()
                SQL);

            try {
                $this->app->make(GateAllocationService::class)->allocate($flight);
                $this->fail('Expected the forced exclusion conflict to escape after bounded retries.');
            } catch (QueryException $exception) {
                $this->assertSame('23P01', $exception->errorInfo[0] ?? null);
            }

            $attempts = DB::scalar('SELECT last_value FROM allocation_retry_probe');

            $this->assertSame(3, (int) $attempts);
            $this->assertDatabaseCount('gate_allocations', 0);
        } finally {
            $this->dropRetryProbe();
        }
    }

    private function dropRetryProbe(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS force_allocation_exclusion_conflict ON gate_allocations');
        DB::unprepared('DROP FUNCTION IF EXISTS force_allocation_exclusion_conflict()');
        DB::unprepared('DROP SEQUENCE IF EXISTS allocation_retry_probe');
    }
}
