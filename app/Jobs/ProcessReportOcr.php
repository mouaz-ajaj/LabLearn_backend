<?php

namespace App\Jobs;

use App\Enums\ExtractionJobStatus;
use App\Enums\ReportStatus;
use App\Models\ExtractionJob;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrResultMapper;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessReportOcr implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [5, 30, 90];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $extractionJobId) {}

    public function uniqueId(): string
    {
        return (string) $this->extractionJobId;
    }

    public function handle(OcrClient $ocrClient, OcrResultMapper $resultMapper): void
    {
        if (! $this->markProcessing()) {
            return;
        }

        $extractionJob = ExtractionJob::query()->with('reportFile')->findOrFail($this->extractionJobId);

        try {
            $response = $ocrClient->analyze(
                $extractionJob->reportFile,
                'lablearn-job-'.$extractionJob->getKey().'-attempt-'.$this->attempts(),
            );
            $rows = $resultMapper->rows($response);
            $this->persistSuccess($response, $rows);
        } catch (OcrException $exception) {
            Log::warning('OCR report processing failed.', [
                'extraction_job_id' => $this->extractionJobId,
                'report_id' => $extractionJob->report_id,
                ...$exception->context(),
            ]);

            if ($exception->retryable) {
                throw $exception;
            }

            $this->markFailed($exception->errorCode, $exception->safeMessage);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof OcrException) {
            $this->markFailed($exception->errorCode, $exception->safeMessage);

            return;
        }

        $this->markFailed('OCR_PROCESSING_FAILED', 'OCR processing failed. Please try again.');

        Log::error('OCR queue job failed permanently.', [
            'extraction_job_id' => $this->extractionJobId,
            'exception' => $exception,
        ]);
    }

    private function markProcessing(): bool
    {
        return DB::transaction(function (): bool {
            $extractionJob = ExtractionJob::query()->whereKey($this->extractionJobId)->lockForUpdate()->firstOrFail();

            if (in_array($extractionJob->status, [ExtractionJobStatus::Succeeded, ExtractionJobStatus::Failed], true)) {
                return false;
            }

            $extractionJob->update([
                'status' => ExtractionJobStatus::Processing,
                'progress' => 20,
                'current_step' => 'OCR_EXTRACTION',
                'attempts' => $this->attempts(),
                'started_at' => $extractionJob->started_at ?? now(),
                'error_code' => null,
                'safe_error_message' => null,
            ]);
            $extractionJob->report()->update(['status' => ReportStatus::Processing]);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<array<string, mixed>>  $rows
     */
    private function persistSuccess(array $response, array $rows): void
    {
        DB::transaction(function () use ($response, $rows): void {
            $extractionJob = ExtractionJob::query()->whereKey($this->extractionJobId)->lockForUpdate()->firstOrFail();

            if ($extractionJob->status === ExtractionJobStatus::Succeeded) {
                return;
            }

            $extractionJob->extractedResults()->delete();
            $extractionJob->extractedResults()->createMany(array_map(
                fn (array $row): array => ['report_id' => $extractionJob->report_id, ...$row],
                $rows,
            ));
            $extractionJob->update([
                'status' => ExtractionJobStatus::Succeeded,
                'progress' => 100,
                'current_step' => 'COMPLETED',
                'engine_version' => is_scalar($response['api_version'] ?? null) ? (string) $response['api_version'] : null,
                'raw_response_json' => $response,
                'completed_at' => now(),
                'failed_at' => null,
                'error_code' => null,
                'safe_error_message' => null,
            ]);
            $extractionJob->report()->update(['status' => ReportStatus::NeedsReview]);
        });
    }

    private function markFailed(string $errorCode, string $safeMessage): void
    {
        DB::transaction(function () use ($errorCode, $safeMessage): void {
            $extractionJob = ExtractionJob::query()->whereKey($this->extractionJobId)->lockForUpdate()->first();

            if ($extractionJob === null || $extractionJob->status === ExtractionJobStatus::Succeeded) {
                return;
            }

            $extractionJob->update([
                'status' => ExtractionJobStatus::Failed,
                'progress' => 100,
                'current_step' => 'FAILED',
                'failed_at' => now(),
                'error_code' => $errorCode,
                'safe_error_message' => $safeMessage,
            ]);
            $extractionJob->report()->update(['status' => ReportStatus::Failed]);
        });
    }
}
