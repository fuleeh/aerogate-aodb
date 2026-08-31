<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('gate_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flight_id')->constrained()->restrictOnDelete();
            $table->foreignId('gate_id')->constrained()->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 32)->default('active');
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE gate_allocations
            ADD COLUMN occupancy_period tstzrange
            GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE gate_allocations
            ADD CONSTRAINT gate_allocations_valid_period
            CHECK (starts_at < ends_at)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE gate_allocations
            ADD CONSTRAINT gate_allocations_valid_status
            CHECK (status IN ('active', 'released', 'cancelled'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX gate_allocations_one_active_per_flight
            ON gate_allocations (flight_id)
            WHERE status = 'active'
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE gate_allocations
            ADD CONSTRAINT gate_allocations_no_active_overlap
            EXCLUDE USING gist (
                gate_id WITH =,
                occupancy_period WITH &&
            )
            WHERE (status = 'active')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_allocations');
    }
};
