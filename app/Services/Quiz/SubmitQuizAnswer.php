<?php

namespace App\Services\Quiz;

use App\Enums\QuizSessionStatus;
use App\Exceptions\ApiException;
use App\Models\QuizSession;
use App\Models\StudentAnswer;
use Illuminate\Support\Facades\DB;

class SubmitQuizAnswer
{
    public function handle(QuizSession $quiz, int $snapshotId, string $selectedOptionId): StudentAnswer
    {
        return DB::transaction(function () use ($quiz, $snapshotId, $selectedOptionId): StudentAnswer {
            $locked = QuizSession::query()->whereKey($quiz->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [QuizSessionStatus::Ready, QuizSessionStatus::InProgress], true)) {
                throw new ApiException('QUIZ_SESSION_NOT_ACTIVE', 'This quiz session cannot accept answers.', 409);
            }

            $snapshot = $locked->questionSnapshots()->whereKey($snapshotId)->first();
            if ($snapshot === null) {
                throw new ApiException('QUIZ_QUESTION_NOT_FOUND', 'This question does not belong to the quiz session.', 422);
            }

            if (! array_key_exists($selectedOptionId, $snapshot->options_json)) {
                throw new ApiException('QUIZ_OPTION_INVALID', 'The selected option does not exist for this question.', 422);
            }

            // Submitted Answer Policy: answers are immutable once accepted - a repeat
            // submission for the same question is rejected rather than overwritten.
            if (StudentAnswer::query()->where('quiz_question_snapshot_id', $snapshot->getKey())->exists()) {
                throw new ApiException('QUIZ_ANSWER_ALREADY_SUBMITTED', 'This question has already been answered.', 409);
            }

            // Correctness is always computed here, server-side, from the immutable
            // snapshot - the client-submitted payload never carries a correctness flag.
            $answer = StudentAnswer::query()->create([
                'quiz_session_id' => $locked->getKey(),
                'quiz_question_snapshot_id' => $snapshot->getKey(),
                'selected_option_id' => $selectedOptionId,
                'is_correct' => $selectedOptionId === $snapshot->correct_option_id,
                'answered_at' => now(),
            ]);

            $answeredCount = StudentAnswer::query()->where('quiz_session_id', $locked->getKey())->count();

            $updates = [];
            if ($locked->status === QuizSessionStatus::Ready) {
                $updates['status'] = QuizSessionStatus::InProgress;
            }
            // Completion is always answered_count === actual_total for THIS session,
            // never a hard-coded number - an 18-question session completes at 18.
            if ($locked->actual_total > 0 && $answeredCount >= $locked->actual_total) {
                $updates['status'] = QuizSessionStatus::Completed;
                $updates['completed_at'] = now();
                $updates['score'] = StudentAnswer::query()
                    ->where('quiz_session_id', $locked->getKey())
                    ->where('is_correct', true)
                    ->count();
            }
            if ($updates !== []) {
                $locked->update($updates);
            }

            return $answer->fresh();
        }, 3);
    }
}
