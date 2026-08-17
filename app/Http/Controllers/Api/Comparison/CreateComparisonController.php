<?php

namespace App\Http\Controllers\Api\Comparison;

use App\Contracts\AiContextualizer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Comparison\CreateComparisonRequest;
use App\Models\User;
use App\Services\Comparison\BuildReportComparison;
use App\Services\Comparison\ResolveComparableReports;
use Illuminate\Http\JsonResponse;

/**
 * Phase 4C - POST /api/v1/comparisons. Entirely read-only/computation-only: never
 * reruns OCR or KBS, never creates or mutates a VerifiedResultSet/Analysis/QuizSession,
 * never persists a "comparison" row. 200 OK is used (not 201/202) since nothing is
 * created and nothing is deferred - this synchronously computes and returns a result,
 * matching the read+compute convention already used by GET /reports/{report}.
 */
class CreateComparisonController extends Controller
{
    public function __construct(
        private readonly ResolveComparableReports $resolveReports,
        private readonly BuildReportComparison $buildComparison,
        private readonly AiContextualizer $aiContextualizer,
    ) {}

    public function __invoke(CreateComparisonRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $language = $request->string('language')->toString();

        $reports = $this->resolveReports->handle($user, $request->validated('report_ids'));
        $comparison = $this->buildComparison->handle($reports);
        $aiResult = $this->aiContextualizer->contextualize($comparison, $user, $language);

        return response()->json([
            'success' => true,
            'data' => [
                'comparison' => $comparison,
                'ai_context' => [
                    'status' => $aiResult->status->value,
                    'language' => $language,
                    'content' => $aiResult->content,
                ],
            ],
        ]);
    }
}
