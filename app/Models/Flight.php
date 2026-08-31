<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlightAllocationStatus;
use App\Enums\GateAllocationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'provider',
    'external_id',
    'airport_icao',
    'callsign',
    'aircraft_icao24',
    'first_observed_at',
    'last_observed_at',
    'arrival_at',
    'allocation_status',
])]
final class Flight extends Model
{
    /** @use HasFactory<\Database\Factories\FlightFactory> */
    use HasFactory;

    /** @return HasMany<GateAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(GateAllocation::class);
    }

    /** @return HasOne<GateAllocation, $this> */
    public function activeAllocation(): HasOne
    {
        return $this->hasOne(GateAllocation::class)
            ->where('status', GateAllocationStatus::Active);
    }

    /**
     * @param Builder<Flight> $query
     * @return Builder<Flight>
     */
    #[Scope]
    protected function pendingAllocation(Builder $query): Builder
    {
        return $query->where('allocation_status', FlightAllocationStatus::Pending);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'arrival_at' => 'immutable_datetime',
            'allocation_status' => FlightAllocationStatus::class,
        ];
    }
}
