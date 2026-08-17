<?php

namespace App\Services\Quiz;

use App\Enums\QuizSessionStatus;
use App\Enums\ReportTestCategory;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Phase 4D: Student-only quiz history + statistics. Reads exclusively from the
 * already-persisted quiz_sessions table - no question/answer content is touched here
 * (that stays in ShowQuizController/QuizSessionResource, reused as-is for the detail
 * screen). Only COMPLETED sessions count toward history/statistics; PREPARING/READY/
 * IN_PROGRESS/FAILED sessions have no final score and are excluded, matching
 * quiz_sessions.score only ever being set at completion (see SubmitQuizAnswer).
 *
 * `summary` is always computed across ALL of the student's completed quizzes,
 * independent of the `category` filter applied to `items` - the Dashboard has no
 * category filter at all and needs the student's true overall performance, and a
 * History screen filtered to one category should not make the headline statistic
 * silently mean something narrower than "overall". See docs/phase-4d-quiz-history.md.
 */
class BuildQuizHistoryOverview
{
    /** @return array{summary: array{completed_quizzes: int, correct_answers: int, total_questions: int, overall_percentage: float|null}, paginator: LengthAwarePaginator} */
    public function handle(User $user, ?ReportTestCategory $category, int $perPage, int $page): array
    {
        if ($user->role !== UserRole::Student) {
            throw new ApiException('QUIZ_STUDENT_ONLY', 'Quiz history is available to student accounts only.', 403);
        }

        return [
            'summary' => $this->summary($user),
            'paginator' => $this->paginate($user, $category, $perPage, $page),
        ];
    }

    /** @return array{completed_quizzes: int, correct_answers: int, total_questions: int, overall_percentage: float|null} */
    private function summary(User $user): array
    {
        $row = QuizSession::query()
            ->where('user_id', $user->getKey())
            ->where('status', QuizSessionStatus::Completed)
            ->selectRaw('COUNT(*) as completed_quizzes, COALESCE(SUM(score), 0) as correct_answers, COALESCE(SUM(actual_total), 0) as total_questions')
            ->first();

        $completedQuizzes = (int) $row->completed_quizzes;
        $correctAnswers = (int) $row->correct_answers;
        $totalQuestions = (int) $row->total_questions;

        return [
            'completed_quizzes' => $completedQuizzes,
            'correct_answers' => $correctAnswers,
            'total_questions' => $totalQuestions,
            // Weighted overall percentage - SUM(correct) / SUM(actual_total), never an
            // average of each quiz's own percentage, so larger quizzes count for more
            // than smaller ones. Never fabricated: null (not 0%) when nothing has been
            // completed yet, so the frontend can render an honest empty state instead
            // of a misleading "0%" performance figure.
            'overall_percentage' => $totalQuestions > 0 ? round($correctAnswers / $totalQuestions * 100, 1) : null,
        ];
    }

    private function paginate(User $user, ?ReportTestCategory $category, int $perPage, int $page): LengthAwarePaginator
    {
        $query = QuizSession::query()
            ->where('user_id', $user->getKey())
            ->where('status', QuizSessionStatus::Completed);

        if ($category !== null) {
            $query->where('report_category', $category->value);
        }

        return $query
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }
}
