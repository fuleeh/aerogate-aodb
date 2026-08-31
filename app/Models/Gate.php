<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['code', 'is_active', 'allocation_priority'])]
final class Gate extends Model
{
    /** @use HasFactory<\Database\Factories\GateFactory> */
    use HasFactory;

    /**
     * @return HasMany<GateUnavailability, $this>
     */
    public function unavailabilities(): HasMany
    {
        return $this->hasMany(GateUnavailability::class);
    }

    /**
     * @param Builder<Gate> $query
     * @return Builder<Gate>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<Gate> $query
     * @return Builder<Gate>
     */
    #[Scope]
    protected function availableDuring(
        Builder $query,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
    ): Builder {
        if ($startsAt >= $endsAt) {
            throw new InvalidArgumentException('Availability window start must be before its end.');
        }

        return $query
            ->active()
            ->whereDoesntHave(
                'unavailabilities',
                static fn (Builder $unavailabilities): Builder => $unavailabilities->whereRaw(
                    "unavailability_period && tstzrange(?, ?, '[)')",
                    [$startsAt, $endsAt],
                ),
            )
            ->orderBy('allocation_priority')
            ->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allocation_priority' => 'integer',
        ];
    }
}
