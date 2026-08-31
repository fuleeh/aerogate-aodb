<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Auditing\AllocationAuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

#[Signature('flights:audit')]
#[Description('Report gate allocation statistics and operational anomalies')]
final class AuditFlightAllocations extends Command
{
    public function handle(AllocationAuditService $auditService): int
    {
        $runId = (string) Str::uuid();
        $startedAt = hrtime(true);

        Log::info('allocation_audit.started', ['run_id' => $runId]);

        try {
            $report = $auditService->audit();
        } catch (Throwable $exception) {
            Log::error('allocation_audit.failed', [
                'run_id' => $runId,
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'exception' => $exception,
            ]);

            $this->error("Allocation audit failed. Run ID: $runId");

            return self::FAILURE;
        }

        $metrics = [
            ...$report->metrics(),
            'anomalies_total' => $report->anomaliesTotal,
        ];

        Log::info('allocation_audit.completed', [
            'run_id' => $runId,
            'audited_at' => $report->auditedAt->format(DATE_ATOM),
            'duration_ms' => $this->durationMilliseconds($startedAt),
            ...$metrics,
        ]);

        $this->info(sprintf('Allocation audit complete. Run ID: %s', $runId));

        foreach ($metrics as $name => $value) {
            $this->line(sprintf('%s=%d', $name, $value));
        }

        return self::SUCCESS;
    }

    private function durationMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
