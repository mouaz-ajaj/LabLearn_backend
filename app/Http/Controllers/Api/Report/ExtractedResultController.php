<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExtractedResultResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExtractedResultController extends Controller
{
    public function __invoke(Request $request, Report $report): JsonResponse
    {
        Gate::authorize('view', $report);
        $extractionJob = $report->extractionJobs()
            ->with(['extractedResults' => fn ($query) => $query->oldest('id')])
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'report_id' => $report->getKey(),
                'extraction_job_id' => $extractionJob?->getKey(),
                'report_status' => $report->status->value,
                'patient_age_years' => $report->patient_age_years,
                'patient_sex' => $report->patient_sex?->value,
                'job_status' => $extractionJob?->status->value,
                'results' => ExtractedResultResource::collection($extractionJob?->extractedResults ?? collect())->resolve($request),
            ],
        ]);
    }
}
