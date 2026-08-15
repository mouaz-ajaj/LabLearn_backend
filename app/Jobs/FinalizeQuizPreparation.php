<?php

namespace App\Jobs;

use App\Enums\QuizSessionStatus;
use App\Models\QuizSession;
use App\Services\Quiz\FinalizeQuizSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Finishes a PREPARING QuizSession once its linked (quiz-first) Analysis has reached a
 * terminal state. Dispatched from ProcessReportAnalysis after that Analysis job
 * succeeds or fails — see dispatchPendingQuizFinalizations() there. Not dispatched at
 * all for the common warm-cache case, where StartQuizSession finalizes synchronously.
 */
class FinalizeQuizPreparation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $quizSessionId) {}

    public function uniqueId(): string
    {
        return (string) $this->quizSessionId;
    }

    public function handle(FinalizeQuizSession $finalize): void
    {
        $session = QuizSession::query()->find($this->quizSessionId);
        if ($session === null || $session->status !== QuizSessionStatus::Preparing) {
            return;
        }

        $finalize->handle($session);
    }
}
