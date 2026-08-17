<?php

namespace App\Services\Reports;

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\VerifiedResultSet;

/**
 * Picks the single Analysis that represents a verified result set's historical
 * result for Phase 4B Report Details.
 *
 * A verified result set can legitimately have more than one Analysis row: `flow`
 * (direct-result vs quiz-first) is part of `Analysis.identity_key`, so a quiz-first
 * attempt never collides with a direct-result attempt for the same report/version
 * (see docs/phase-3b-quiz.md). A re-run after a KBS ruleset upgrade can also add a
 * second direct-result row without replacing the first. This service resolves that
 * ambiguity deterministically, mirroring the same "quiz-first is never a reachable
 * Final Result until its quiz is completed" principle already enforced by
 * Analysis::isPendingQuizCompletion() / ShowAnalysisController:
 *
 * 1. The most recently completed SUCCEEDED direct-result analysis, if any.
 * 2. Otherwise, the most recently completed SUCCEEDED quiz-first analysis whose
 *    quiz has actually been completed (never a locked/pending one).
 * 3. Otherwise, the most recently failed analysis (any flow), so a genuine failure
 *    can be shown honestly instead of silently omitted.
 * 4. Otherwise, the most recent still-queued/processing analysis, so an in-flight
 *    attempt is reflected instead of appearing as "no analysis at all".
 * 5. Otherwise null - no analysis has ever been attempted for this version.
 */
class SelectHistoricalAnalysis
{
    public function forVerifiedResultSet(VerifiedResultSet $verifiedResultSet): ?Analysis
    {
        $succeededDirect = $verifiedResultSet->analyses()
            ->where('status', AnalysisStatus::Succeeded)
            ->where('flow', AnalysisFlow::DirectResult)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if ($succeededDirect !== null) {
            return $succeededDirect;
        }

        $succeededQuiz = $verifiedResultSet->analyses()
            ->where('status', AnalysisStatus::Succeeded)
            ->where('flow', AnalysisFlow::QuizFirst)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Analysis $analysis): bool => ! $analysis->isPendingQuizCompletion());

        if ($succeededQuiz !== null) {
            return $succeededQuiz;
        }

        $failed = $verifiedResultSet->analyses()
            ->where('status', AnalysisStatus::Failed)
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->first();

        if ($failed !== null) {
            return $failed;
        }

        return $verifiedResultSet->analyses()
            ->whereIn('status', [AnalysisStatus::Queued, AnalysisStatus::Processing])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
