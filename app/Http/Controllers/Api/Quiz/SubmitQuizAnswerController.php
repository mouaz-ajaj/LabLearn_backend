<?php

namespace App\Http\Controllers\Api\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\SubmitQuizAnswerRequest;
use App\Http\Resources\QuizSessionResource;
use App\Models\QuizSession;
use App\Services\Quiz\SubmitQuizAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SubmitQuizAnswerController extends Controller
{
    public function __invoke(SubmitQuizAnswerRequest $request, QuizSession $quiz, SubmitQuizAnswer $submit): JsonResponse
    {
        Gate::authorize('view', $quiz);

        $answer = $submit->handle(
            $quiz,
            $request->integer('question_snapshot_id'),
            $request->string('selected_option_id')->toString(),
        );
        $snapshot = $answer->questionSnapshot;
        $quiz->refresh()->load('questionSnapshots.studentAnswer');

        return response()->json([
            'success' => true,
            'message' => 'Answer submitted.',
            'data' => [
                'answer' => [
                    'question_snapshot_id' => $snapshot->getKey(),
                    'selected_option_id' => $answer->selected_option_id,
                    'correct' => $answer->is_correct,
                    'correct_option_id' => $snapshot->correct_option_id,
                    'explanation' => $snapshot->explanation_json,
                ],
                'session' => QuizSessionResource::make($quiz)->resolve($request),
            ],
        ]);
    }
}
