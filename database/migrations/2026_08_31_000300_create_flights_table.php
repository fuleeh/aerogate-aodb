<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('external_id');
            $table->char('airport_icao', 4);
            $table->string('callsign', 16)->nullable();
            $table->char('aircraft_icao24', 6)->nullable();
            $table->timestampTz('first_observed_at');
            $table->timestampTz('last_observed_at');
            $table->timestampTz('arrival_at')->nullable();
            $table->string('allocation_status', 32)->default('pending');
            $table->timestampsTz();

            $table->unique(['provider', 'external_id']);
            $table->index(['airport_icao', 'allocation_status', 'first_observed_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE flights
            ADD CONSTRAINT flights_valid_airport_icao
            CHECK (airport_icao ~ '^[A-Z0-9]{4}$')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE flights
            ADD CONSTRAINT flights_valid_observation_period
            CHECK (first_observed_at <= last_observed_at)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE flights
            ADD CONSTRAINT flights_valid_allocation_status
            CHECK (allocation_status IN ('pending', 'allocated', 'unassigned'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
