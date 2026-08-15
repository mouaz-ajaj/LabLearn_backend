<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\UploadReportFileRequest;
use App\Models\Report;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ReportFileController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    public function __invoke(UploadReportFileRequest $request, Report $report): JsonResponse
    {
        Gate::authorize('update', $report);
        $reportFile = $this->reportService->storeFile($report, $request->file('file'));

        return response()->json([
            'success' => true,
            'message' => 'Report file uploaded successfully.',
            'data' => [
                'report_id' => $report->getKey(),
                'file' => [
                    'id' => $reportFile->getKey(),
                    'original_name' => $reportFile->original_name,
                    'mime_type' => $reportFile->mime_type,
                    'extension' => $reportFile->extension,
                    'size_bytes' => $reportFile->size_bytes,
                    'checksum' => $reportFile->checksum,
                ],
            ],
        ], Response::HTTP_CREATED);
    }
}
