<?php

declare(strict_types=1);

namespace App\Application\Auditing;

use App\Domain\Auditing\AllocationAuditReport;
use App\Infrastructure\Persistence\AllocationAuditReader;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class AllocationAuditService
{
    public function __construct(
        private AllocationAuditReader $reader,
        private AllocationAuditConfiguration $configuration,
        private ClockInterface $clock,
    ) {
    }

    public function audit(): AllocationAuditReport
    {
        $auditedAt = DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
        $staleBefore = $auditedAt->sub(
            new DateInterval(sprintf('PT%dM', $this->configuration->staleUnassignedAfterMinutes)),
        );

        return $this->reader->snapshot($auditedAt, $staleBefore);
    }
}
