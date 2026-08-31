<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('gate_unavailabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gate_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason');
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE gate_unavailabilities
            ADD COLUMN unavailability_period tstzrange
            GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE gate_unavailabilities
            ADD CONSTRAINT gate_unavailabilities_valid_period CHECK (starts_at < ends_at)
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX gate_unavailabilities_period_gist
            ON gate_unavailabilities USING gist (unavailability_period)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_unavailabilities');
    }
};
