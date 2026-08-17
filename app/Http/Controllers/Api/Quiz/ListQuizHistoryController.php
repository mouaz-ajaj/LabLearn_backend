<?php

namespace App\Http\Controllers\Api\Quiz;

use App\Enums\ReportTestCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\ListQuizHistoryRequest;
use App\Http\Resources\QuizHistoryItemResource;
use App\Models\User;
use App\Services\Quiz\BuildQuizHistoryOverview;
use Illuminate\Http\JsonResponse;

/**
 * Phase 4D - GET /api/v1/students/me/quiz-history. Student-only (403
 * QUIZ_STUDENT_ONLY, enforced in BuildQuizHistoryOverview - the same convention
 * StartQuizSession already uses for quiz creation). user_id always comes from the
 * authenticated token, never from client input. Read-only: builds nothing, reruns
 * nothing, mutates nothing.
 */
class ListQuizHistoryController extends Controller
{
    public function __invoke(ListQuizHistoryRequest $request, BuildQuizHistoryOverview $overview): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $category = $request->validated('test_category') !== null
            ? ReportTestCategory::from($request->validated('test_category'))
            : null;

        $result = $overview->handle($user, $category, $request->perPage(), (int) ($request->validated('page') ?? 1));
        $paginator = $result['paginator'];

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $result['summary'],
                'items' => QuizHistoryItemResource::collection($paginator->items())->resolve($request),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
        ]);
    }
}
