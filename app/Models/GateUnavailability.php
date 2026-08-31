<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['gate_id', 'starts_at', 'ends_at', 'reason'])]
final class GateUnavailability extends Model
{
    /** @use HasFactory<\Database\Factories\GateUnavailabilityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Gate, $this>
     */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
