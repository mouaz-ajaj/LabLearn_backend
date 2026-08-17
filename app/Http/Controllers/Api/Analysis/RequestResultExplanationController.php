<?php

namespace App\Http\Controllers\Api\Analysis;

use App\Contracts\ResultExplainer;
use App\Enums\AnalysisStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\RequestResultExplanationRequest;
use App\Models\Analysis;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4E - POST /api/v1/analyses/{analysis}/explanation. Reuses the exact same
 * ownership (AnalysisPolicy::view) and quiz-result-lock (isPendingQuizCompletion)
 * checks ShowAnalysisController already enforces, so an explanation can never be
 * read anywhere the underlying Final Result itself could not be. POST is used (not
 * GET) because a cache miss causes a real write (a new ai_explanations row) - see
 * docs/phase-4e-result-explanation.md "Endpoint semantics".
 */
class RequestResultExplanationController extends Controller
{
    public function __construct(private readonly ResultExplainer $explainer) {}

    public function __invoke(RequestResultExplanationRequest $request, Analysis $analysis): JsonResponse
    {
        Gate::authorize('view', $analysis);

        if ($analysis->isPendingQuizCompletion()) {
            throw new ApiException(
                'QUIZ_RESULT_LOCKED',
                'This report result is locked until the associated quiz is completed.',
                403,
            );
        }

        if ($analysis->status !== AnalysisStatus::Succeeded) {
            throw new ApiException(
                'EXPLANATION_NOT_AVAILABLE',
                'An explanation is only available once the analysis has succeeded.',
                409,
            );
        }

        /** @var User $user */
        $user = $request->user();
        $role = $user->role === UserRole::Student ? 'student' : 'regular';
        $language = $request->string('language')->toString();

        $analysis->loadMissing(['conclusions', 'ruleTraces']);
        $result = $this->explainer->explain($analysis, $role, $language);

        return response()->json([
            'success' => true,
            'data' => [
                'analysis_id' => $analysis->getKey(),
                'status' => $result->status->value,
                'language' => $language,
                'role' => $role,
                'content' => $result->content,
            ],
        ]);
    }
}
