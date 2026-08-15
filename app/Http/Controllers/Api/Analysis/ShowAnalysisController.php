<?php

namespace App\Http\Controllers\Api\Analysis;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnalysisResource;
use App\Models\Analysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShowAnalysisController extends Controller
{
    public function __invoke(Request $request, Analysis $analysis): JsonResponse
    {
        Gate::authorize('view', $analysis);

        // Result Locking: a quiz-first analysis is real and persisted (Case-Specific
        // question generation needs it), but its owner cannot read the Final Result
        // through this endpoint — or any endpoint, since this is the only read path —
        // until the quiz that depends on it is COMPLETED. This is enforced here, not
        // just hidden by the mobile client, so calling this endpoint directly cannot
        // bypass the quiz. Direct-result analyses are never affected.
        if ($analysis->isPendingQuizCompletion()) {
            throw new ApiException(
                'QUIZ_RESULT_LOCKED',
                'This report result is locked until the associated quiz is completed.',
                403,
            );
        }

        $analysis->load(['conclusions', 'ruleTraces', 'verifiedResultSet.results']);

        return response()->json([
            'success' => true,
            'message' => 'Analysis status retrieved.',
            'data' => AnalysisResource::make($analysis)->resolve($request),
        ]);
    }
}
