<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnalysisResource;
use App\Http\Resources\HistoricalVerifiedResultResource;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\SelectHistoricalAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4B - read-only historical Report Details. Never reruns OCR/KBS, never
 * creates or mutates a VerifiedResultSet/Analysis/QuizSession; it only reads what
 * is already persisted.
 */
class ShowReportController extends Controller
{
    public function __construct(private readonly SelectHistoricalAnalysis $selectHistoricalAnalysis) {}

    public function __invoke(Request $request, Report $report): JsonResponse
    {
        Gate::authorize('view', $report);

        $verifiedResultSet = $report->verifiedResultSets()->with('results')->latest('version')->first();
        $analysis = null;

        if ($verifiedResultSet !== null) {
            $analysis = $this->selectHistoricalAnalysis->forVerifiedResultSet($verifiedResultSet);
            $analysis?->load(['conclusions', 'ruleTraces', 'verifiedResultSet.results']);
        }

        /** @var User $user */
        $user = $request->user();
        $completedQuiz = QuizSession::latestCompletedForReport($report->getKey(), $user->getKey());

        return response()->json([
            'success' => true,
            'data' => [
                'report' => [
                    'id' => $report->getKey(),
                    'test_category' => $report->test_category->value,
                    'source_type' => $report->source_type->value,
                    'status' => $report->status->value,
                    'report_date' => $report->report_date?->toDateString(),
                    'created_at' => $report->created_at?->toISOString(),
                    'updated_at' => $report->updated_at?->toISOString(),
                ],
                'verification' => $verifiedResultSet !== null ? [
                    'id' => $verifiedResultSet->getKey(),
                    'version' => $verifiedResultSet->version,
                    'patient_age_years' => $verifiedResultSet->patient_age_years,
                    'patient_sex' => $verifiedResultSet->patient_sex?->value,
                    'confirmed_at' => $verifiedResultSet->confirmed_at?->toISOString(),
                    'values' => HistoricalVerifiedResultResource::collection($verifiedResultSet->results)->resolve($request),
                ] : null,
                'analysis' => $analysis !== null ? AnalysisResource::make($analysis)->resolve($request) : null,
                'quiz_summary' => $completedQuiz !== null ? [
                    'status' => $completedQuiz->status->value,
                    'score' => $completedQuiz->score,
                    'total' => $completedQuiz->actual_total,
                ] : null,
            ],
        ]);
    }
}
