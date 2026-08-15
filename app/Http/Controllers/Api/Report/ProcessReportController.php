<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExtractionJobResource;
use App\Models\Report;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ProcessReportController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    public function __invoke(Request $request, Report $report): JsonResponse
    {
        Gate::authorize('update', $report);
        $extractionJob = $this->reportService->queueOcr($report);

        return response()->json([
            'success' => true,
            'message' => 'OCR processing queued.',
            'data' => ['job' => ExtractionJobResource::make($extractionJob)->resolve($request)],
        ], Response::HTTP_ACCEPTED);
    }
}
