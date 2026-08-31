<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GateAllocationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['flight_id', 'gate_id', 'starts_at', 'ends_at', 'status'])]
final class GateAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\GateAllocationFactory> */
    use HasFactory;

    /** @return BelongsTo<Flight, $this> */
    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /** @return BelongsTo<Gate, $this> */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => GateAllocationStatus::class,
        ];
    }
}
