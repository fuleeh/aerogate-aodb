<?php

declare(strict_types=1);

namespace App\Application\Auditing;

use InvalidArgumentException;

final readonly class AllocationAuditConfiguration
{
    public function __construct(public int $staleUnassignedAfterMinutes)
    {
        if ($this->staleUnassignedAfterMinutes <= 0) {
            throw new InvalidArgumentException('Audit stale-unassigned threshold must be greater than zero minutes.');
        }
    }

    /** @param array<string, mixed> $configuration */
    public static function fromArray(array $configuration): self
    {
        return new self(
            staleUnassignedAfterMinutes: filter_var(
                $configuration['stale_unassigned_after_minutes'] ?? null,
                FILTER_VALIDATE_INT,
                FILTER_NULL_ON_FAILURE,
            ) ?? throw new InvalidArgumentException('Audit stale-unassigned threshold must be an integer.'),
        );
    }
}
