<?php

namespace App\Http\Controllers\Api\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\StartQuizRequest;
use App\Http\Resources\QuizSessionResource;
use App\Models\Report;
use App\Models\VerifiedResultSet;
use App\Services\Quiz\StartQuizSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StartQuizController extends Controller
{
    public function __invoke(StartQuizRequest $request, Report $report, StartQuizSession $start): JsonResponse
    {
        Gate::authorize('update', $report);
        $set = VerifiedResultSet::query()->findOrFail($request->integer('verified_result_set_id'));
        $session = $start->handle($report, $set, $request->user());
        $session->load('questionSnapshots.studentAnswer');

        return response()->json([
            'success' => true,
            'message' => $session->wasRecentlyCreated ? 'Quiz session created.' : 'Quiz session request accepted.',
            'data' => QuizSessionResource::make($session)->resolve($request),
        ], $session->wasRecentlyCreated ? 201 : 200);
    }
}
