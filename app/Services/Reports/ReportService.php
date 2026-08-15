<?php

namespace App\Services\Reports;

use App\Enums\ExtractionJobStatus;
use App\Enums\ReportStatus;
use App\Exceptions\ApiException;
use App\Jobs\ProcessReportOcr;
use App\Models\ExtractionJob;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReportService
{
    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Report
    {
        return $user->reports()->create($data);
    }

    public function storeFile(Report $report, UploadedFile $uploadedFile): ReportFile
    {
        if ($report->status !== ReportStatus::Uploaded) {
            throw new ApiException('REPORT_NOT_PROCESSABLE', 'The report cannot accept a file in its current state.', Response::HTTP_CONFLICT);
        }

        $disk = (string) config('ocr.storage_disk');
        $extension = Str::lower($uploadedFile->getClientOriginalExtension());
        $directory = "reports/{$report->user_id}/{$report->getKey()}";
        $path = $directory.'/'.Str::uuid().'.'.$extension;
        $checksum = hash_file('sha256', $uploadedFile->getRealPath());
        $oldFile = $report->file()->first();

        if (! is_string($checksum) || ! Storage::disk($disk)->putFileAs($directory, $uploadedFile, basename($path))) {
            throw new ApiException('REPORT_FILE_INVALID', 'The report file could not be stored.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $reportFile = DB::transaction(fn (): ReportFile => $report->file()->updateOrCreate([], [
                'original_name' => (string) Str::of($uploadedFile->getClientOriginalName())->replace('\\', '/')->afterLast('/')->limit(255),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'mime_type' => $uploadedFile->getMimeType() ?: $uploadedFile->getClientMimeType(),
                'extension' => $extension,
                'size_bytes' => $uploadedFile->getSize(),
                'checksum' => $checksum,
            ]));
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        if ($oldFile !== null && $oldFile->storage_path !== $path) {
            Storage::disk($oldFile->storage_disk)->delete($oldFile->storage_path);
        }

        return $reportFile;
    }

    public function queueOcr(Report $report): ExtractionJob
    {
        $extractionJob = DB::transaction(function () use ($report): ExtractionJob {
            $lockedReport = Report::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            $activeJob = $lockedReport->extractionJobs()
                ->whereIn('status', [ExtractionJobStatus::Queued, ExtractionJobStatus::Processing])
                ->exists();

            if ($activeJob) {
                throw new ApiException('OCR_JOB_ALREADY_RUNNING', 'An OCR job is already running for this report.', Response::HTTP_CONFLICT);
            }

            if (! in_array($lockedReport->status, [ReportStatus::Uploaded, ReportStatus::Failed], true)) {
                throw new ApiException('REPORT_NOT_PROCESSABLE', 'The report cannot be processed in its current state.', Response::HTTP_CONFLICT);
            }

            $reportFile = $lockedReport->file()->first();
            if ($reportFile === null) {
                throw new ApiException('REPORT_FILE_REQUIRED', 'A report file is required before processing.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $job = $lockedReport->extractionJobs()->create([
                'report_file_id' => $reportFile->getKey(),
                'status' => ExtractionJobStatus::Queued,
                'progress' => 0,
                'current_step' => 'QUEUED',
                'engine_name' => (string) config('ocr.engine_name'),
            ]);

            $lockedReport->update(['status' => ReportStatus::Queued]);

            return $job;
        });

        ProcessReportOcr::dispatch($extractionJob->getKey());

        return $extractionJob;
    }
}
