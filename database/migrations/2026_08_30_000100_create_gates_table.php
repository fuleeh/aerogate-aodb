<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('gates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('allocation_priority')->default(100);
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE gates ADD CONSTRAINT gates_allocation_priority_positive CHECK (allocation_priority > 0)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('gates');
    }
};
