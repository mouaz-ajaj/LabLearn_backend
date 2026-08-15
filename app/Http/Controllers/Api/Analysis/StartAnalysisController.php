<?php

namespace App\Http\Controllers\Api\Analysis;

use App\Enums\AnalysisFlow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analysis\StartAnalysisRequest;
use App\Http\Resources\AnalysisResource;
use App\Models\Report;
use App\Models\VerifiedResultSet;
use App\Services\Analysis\StartReportAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StartAnalysisController extends Controller
{
    public function __invoke(StartAnalysisRequest $request, Report $report, StartReportAnalysis $start): JsonResponse
    {
        Gate::authorize('update', $report);
        $set = VerifiedResultSet::query()->findOrFail($request->integer('verified_result_set_id'));
        $analysis = $start->handle($report, $set, $request->user(), AnalysisFlow::from($request->string('flow')->toString()));

        return response()->json([
            'success' => true,
            'message' => $analysis->wasRecentlyCreated ? 'Analysis queued.' : 'Analysis request accepted.',
            'data' => AnalysisResource::make($analysis)->resolve($request),
        ], $analysis->wasRecentlyCreated ? 202 : 200);
    }
}
