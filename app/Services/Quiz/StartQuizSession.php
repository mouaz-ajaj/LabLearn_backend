<?php

namespace App\Services\Quiz;

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\QuizSessionStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Analysis\StartReportAnalysis;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates quiz-first preparation end to end:
 *
 *   validate request -> idempotent QuizSession row (PREPARING)
 *   -> reuse-or-start an internal quiz-first Analysis for the exact verified version
 *      (same KBS pipeline/idempotency StartReportAnalysis already uses for direct-result)
 *   -> if the Analysis is already terminal (warm cache / already SUCCEEDED or FAILED),
 *      finish the quiz synchronously in this same request (READY or FAILED)
 *   -> otherwise leave the quiz PREPARING; ProcessReportAnalysis finishes it later
 *      (see FinalizeQuizPreparation) once the queued KBS analysis completes
 *
 * The internal Analysis is never directly reachable by the client: AnalysisPolicy
 * gates any flow=quiz-first Analysis until its quiz session is COMPLETED.
 */
class StartQuizSession
{
    public function __construct(
        private readonly StartReportAnalysis $startReportAnalysis,
        private readonly FinalizeQuizSession $finalizeQuizSession,
    ) {}

    public function handle(Report $report, VerifiedResultSet $set, User $user): QuizSession
    {
        if ($user->role !== UserRole::Student) {
            throw new ApiException('QUIZ_STUDENT_ONLY', 'Quiz is available to student accounts only.', 403);
        }
        if ($set->report_id !== $report->getKey() || $set->confirmed_by_user_id !== $user->getKey()) {
            throw new ApiException('VERIFIED_RESULT_SET_INVALID', 'The verified result set does not belong to this report.', 422);
        }
        if ($set->category_gate_status !== 'MATCH' || $set->category_gate_category !== $report->test_category->value) {
            throw new ApiException('CATEGORY_GATE_NOT_MATCHED', 'The verified results did not pass the selected category gate.', 409);
        }
        if (! in_array($report->status, [ReportStatus::Verified, ReportStatus::Analyzed, ReportStatus::Completed], true)) {
            throw new ApiException('QUIZ_NOT_PROCESSABLE', 'Only a verified report can start a quiz.', 409);
        }
        if (! $set->results()->exists()) {
            throw new ApiException('VERIFIED_RESULTS_EMPTY', 'At least one verified result is required.', 422);
        }

        $targetGeneral = (int) config('quiz.preferred_general_count');
        $targetCaseSpecific = (int) config('quiz.preferred_case_specific_count');

        // Server-derived idempotency key, mirroring StartReportAnalysis: a double tap
        // (or a legitimate retry) with the same inputs must resolve to the same session
        // rather than reshuffling or duplicating one.
        $identity = hash('sha256', implode('|', [
            $report->getKey(), $set->getKey(), $set->version, $user->getKey(), $targetGeneral, $targetCaseSpecific,
        ]));

        return DB::transaction(function () use ($report, $set, $user, $targetGeneral, $targetCaseSpecific, $identity): QuizSession {
            Report::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            $session = QuizSession::query()->where('identity_key', $identity)->lockForUpdate()->first();
            if ($session !== null && $session->status !== QuizSessionStatus::Failed) {
                return $session;
            }

            $values = [
                'user_id' => $user->getKey(),
                'report_id' => $report->getKey(),
                'verified_result_set_id' => $set->getKey(),
                'verified_result_set_version' => $set->version,
                'report_category' => $report->test_category->value,
                'identity_key' => $identity,
                'target_total' => $targetGeneral + $targetCaseSpecific,
                'target_general_count' => $targetGeneral,
                'target_case_specific_count' => $targetCaseSpecific,
                'status' => QuizSessionStatus::Preparing,
                'started_at' => now(),
            ];

            if ($session === null) {
                $session = QuizSession::query()->create($values + [
                    'actual_total' => 0,
                    'actual_general_count' => 0,
                    'actual_case_specific_count' => 0,
                ]);
            } else {
                // Previous attempt FAILED (e.g. zero eligible questions, or the internal
                // analysis failed) — rebuild from scratch; the question bank or KBS
                // conditions may have changed since.
                $session->questionSnapshots()->delete();
                $session->update($values + ['prepare_error_code' => null]);
            }

            // Reuse-or-start the internal quiz-first analysis for this exact verified
            // version. This is the SAME idempotent service/identity_key mechanism the
            // direct-result flow uses, just invoked with internal=true so the public
            // 409 guard for flow=quiz-first is bypassed only for this call site.
            $analysis = null;
            try {
                $analysis = $this->startReportAnalysis->handle($report, $set, $user, AnalysisFlow::QuizFirst, internal: true);
                $session->update(['analysis_id' => $analysis->getKey()]);
            } catch (ApiException $exception) {
                // KBS being unreachable/unsupported (503) or rejecting this input during
                // preflight (422 ANALYSIS_INPUT_INVALID) must not fail the whole quiz —
                // per the Dynamic Quiz Size / Missing-or-Insufficient-KBS-Data policy, a
                // General-only quiz (0 Case-Specific) is still an acceptable outcome. Any
                // other exception (e.g. a guard StartQuizSession itself already validated)
                // is a genuine unexpected state and should still surface.
                if ($exception->status !== 503 && $exception->errorCode !== 'ANALYSIS_INPUT_INVALID') {
                    throw $exception;
                }
            }

            if ($analysis === null || in_array($analysis->status, [AnalysisStatus::Succeeded, AnalysisStatus::Failed], true)) {
                // Warm path: either there is no Analysis to wait for at all (KBS was
                // unavailable/rejected the input — General-only), or KBS already has a
                // result (or already failed) for this exact report+verified version —
                // finish the quiz in this same request instead of making the client poll
                // for something that is already known.
                $wasRecentlyCreated = $session->wasRecentlyCreated;
                // FinalizeQuizSession re-queries under its own lock and returns that
                // fresh instance, which would otherwise silently lose wasRecentlyCreated
                // (a freshly-queried model always defaults it to false) and make the
                // controller report 200 instead of 201 for a session created moments ago
                // in this same request.
                $session = $this->finalizeQuizSession->handle($session);
                $session->wasRecentlyCreated = $wasRecentlyCreated;
            }
            // Otherwise the analysis is QUEUED/PROCESSING: the quiz stays PREPARING and
            // ProcessReportAnalysis will dispatch FinalizeQuizPreparation once it lands.

            return $session;
        }, 3);
    }
}
