<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FlightAllocationStatus;
use App\Models\Flight;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FlightPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normalized_flight_is_persisted_with_typed_values(): void
    {
        $flight = Flight::factory()->create([
            'provider' => 'opensky',
            'external_id' => 'abc123:1788134400:EDDF',
            'first_observed_at' => '2026-08-31 10:00:00+00',
            'last_observed_at' => '2026-08-31 10:05:00+00',
            'allocation_status' => FlightAllocationStatus::Pending,
        ]);

        self::assertSame(FlightAllocationStatus::Pending, $flight->allocation_status);
        self::assertInstanceOf(CarbonImmutable::class, $flight->first_observed_at);
        self::assertInstanceOf(CarbonImmutable::class, $flight->last_observed_at);
    }

    public function test_provider_and_external_id_form_a_unique_identity(): void
    {
        Flight::factory()->create([
            'provider' => 'opensky',
            'external_id' => 'same-flight',
        ]);

        $this->expectException(QueryException::class);

        Flight::factory()->create([
            'provider' => 'opensky',
            'external_id' => 'same-flight',
        ]);
    }

    public function test_different_providers_may_use_the_same_external_id(): void
    {
        Flight::factory()->create([
            'provider' => 'opensky',
            'external_id' => 'provider-owned-id',
        ]);
        Flight::factory()->create([
            'provider' => 'another-provider',
            'external_id' => 'provider-owned-id',
        ]);

        self::assertSame(2, Flight::query()->count());
    }

    public function test_callsign_is_not_used_as_flight_identity(): void
    {
        Flight::factory()->count(2)->create(['callsign' => 'DLH123']);

        self::assertSame(2, Flight::query()->where('callsign', 'DLH123')->count());
    }

    public function test_optional_provider_metadata_may_be_missing(): void
    {
        $flight = Flight::factory()->withoutOptionalMetadata()->create();

        self::assertNull($flight->callsign);
        self::assertNull($flight->aircraft_icao24);
        self::assertNull($flight->arrival_at);
    }

    public function test_the_database_rejects_observation_time_moving_backwards(): void
    {
        $this->expectException(QueryException::class);

        Flight::factory()->create([
            'first_observed_at' => '2026-08-31 10:00:00+00',
            'last_observed_at' => '2026-08-31 09:59:59+00',
        ]);
    }

    public function test_the_database_rejects_an_unknown_allocation_status(): void
    {
        $flight = Flight::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('flights')
            ->where('id', $flight->id)
            ->update(['allocation_status' => 'cancelled']);
    }

    public function test_pending_scope_returns_only_flights_awaiting_allocation(): void
    {
        $pending = Flight::factory()->create();
        Flight::factory()->create(['allocation_status' => FlightAllocationStatus::Allocated]);

        self::assertSame([$pending->id], Flight::query()->pendingAllocation()->pluck('id')->all());
    }
}
