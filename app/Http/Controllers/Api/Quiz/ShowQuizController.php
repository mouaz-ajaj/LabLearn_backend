<?php

namespace App\Http\Controllers\Api\Quiz;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuizSessionResource;
use App\Models\QuizSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShowQuizController extends Controller
{
    public function __invoke(Request $request, QuizSession $quiz): JsonResponse
    {
        Gate::authorize('view', $quiz);
        $quiz->load('questionSnapshots.studentAnswer');

        return response()->json([
            'success' => true,
            'message' => 'Quiz session retrieved.',
            'data' => QuizSessionResource::make($quiz)->resolve($request),
        ]);
    }
}
