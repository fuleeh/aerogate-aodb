<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Ingestion\FlightIngestionConfiguration;
use App\Application\Ingestion\FlightIngestionService;
use App\Contracts\FlightProviders\FlightProviderException;
use App\Domain\Ingestion\FlightIngestionSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Throwable;

#[Signature('flights:fetch-and-allocate')]
#[Description('Fetch airport arrivals and allocate them to available gates')]
final class FetchAndAllocateFlights extends Command
{
    public function handle(
        FlightIngestionService $ingestionService,
        FlightIngestionConfiguration $configuration,
        ClockInterface $clock,
    ): int {
        $runId = (string) Str::uuid();
        $startedAt = hrtime(true);
        $query = $configuration->arrivalQuery($clock->now());
        $context = [
            'run_id' => $runId,
            'airport_icao' => $query->airport->value,
            'query_starts_at' => $query->startsAt->format(DATE_ATOM),
            'query_ends_at' => $query->endsAt->format(DATE_ATOM),
        ];

        Log::info('flight_ingestion.started', $context);

        try {
            $summary = $ingestionService->ingest($query);
        } catch (FlightProviderException $exception) {
            Log::error('flight_ingestion.failed', [
                ...$context,
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'failure_type' => 'provider',
                'failure_reason' => $exception->reason->value,
                'item_index' => $exception->itemIndex,
                'exception' => $exception,
            ]);

            $this->error("Flight ingestion failed. Run ID: $runId");

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('flight_ingestion.failed', [
                ...$context,
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'failure_type' => 'internal',
                'exception' => $exception,
            ]);

            $this->error("Flight ingestion failed. Run ID: $runId");

            return self::FAILURE;
        }

        foreach ($summary->failures as $failure) {
            Log::warning('flight_ingestion.item_failed', [
                ...$context,
                'provider' => $failure->provider,
                'external_id' => $failure->externalId,
                'exception' => $failure->exception,
            ]);
        }

        $completedContext = [
            ...$context,
            'duration_ms' => $this->durationMilliseconds($startedAt),
            'processed' => $summary->processed(),
            'created' => $summary->created,
            'updated' => $summary->updated,
            'allocated' => $summary->allocated,
            'unassigned' => $summary->unassigned,
            'failed' => $summary->failed,
        ];

        if ($summary->failed > 0) {
            Log::error('flight_ingestion.completed_with_failures', $completedContext);
            $this->error($this->summaryLine($summary, $runId));

            return self::FAILURE;
        }

        Log::info('flight_ingestion.completed', $completedContext);
        $this->info($this->summaryLine($summary, $runId));

        return self::SUCCESS;
    }

    private function durationMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function summaryLine(FlightIngestionSummary $summary, string $runId): string
    {
        return sprintf(
            'Flight ingestion complete: processed=%d created=%d updated=%d allocated=%d unassigned=%d failed=%d. Run ID: %s',
            $summary->processed(),
            $summary->created,
            $summary->updated,
            $summary->allocated,
            $summary->unassigned,
            $summary->failed,
            $runId,
        );
    }
}
