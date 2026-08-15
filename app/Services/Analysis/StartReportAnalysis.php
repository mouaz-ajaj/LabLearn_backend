<?php

namespace App\Services\Analysis;

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Jobs\ProcessReportAnalysis;
use App\Models\Analysis;
use App\Models\Report;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsException;
use App\Services\Kbs\KbsRequestMapper;
use Illuminate\Support\Facades\DB;

class StartReportAnalysis
{
    public function __construct(
        private readonly KbsClient $kbsClient,
        private readonly KbsRequestMapper $requestMapper,
    ) {}

    /**
     * @param  bool  $internal  Set only by the Quiz domain (StartQuizSession) to run a
     *                          quiz-first analysis internally so Case-Specific questions
     *                          can be built from real evidence. The public
     *                          `POST /reports/{report}/analyze` endpoint never passes
     *                          this — a Student/Regular request with flow=quiz-first is
     *                          still rejected exactly as before. The resulting Analysis
     *                          is never directly reachable by the client either: it is
     *                          gated by AnalysisPolicy::view() until its quiz completes.
     */
    public function handle(Report $report, VerifiedResultSet $set, User $user, AnalysisFlow $flow, bool $internal = false): Analysis
    {
        if ($flow === AnalysisFlow::QuizFirst && ! $internal) {
            $message = $user->role === UserRole::Student
                ? 'Quiz-first is reserved for the Phase 3B learning flow.'
                : 'Quiz-first is available only to student accounts.';
            throw new ApiException('ANALYSIS_NOT_PROCESSABLE', $message, 409);
        }
        if ($set->report_id !== $report->getKey() || $set->confirmed_by_user_id !== $user->getKey()) {
            throw new ApiException('VERIFIED_RESULT_SET_INVALID', 'The verified result set does not belong to this report.', 422);
        }
        if ($set->category_gate_status !== 'MATCH' || $set->category_gate_category !== $report->test_category->value) {
            throw new ApiException('CATEGORY_GATE_NOT_MATCHED', 'The verified results did not pass the selected category gate.', 409);
        }
        if (! in_array($report->status, [ReportStatus::Verified, ReportStatus::Analyzed, ReportStatus::Completed], true)) {
            throw new ApiException('ANALYSIS_NOT_PROCESSABLE', 'Only a verified report can be analyzed.', 409);
        }
        if (! $set->results()->exists()) {
            throw new ApiException('VERIFIED_RESULTS_EMPTY', 'At least one verified result is required.', 422);
        }

        try {
            $metadata = $this->kbsClient->metadata();
        } catch (KbsException $exception) {
            throw new ApiException($exception->errorCode, $exception->getMessage(), 503);
        }
        if (($metadata['input_schema_version'] ?? null) !== '1' || ($metadata['output_schema_version'] ?? null) !== '1') {
            throw new ApiException('KBS_SCHEMA_UNSUPPORTED', 'The analysis service contract version is not supported.', 503);
        }

        // Authoritative preflight: resolve analyte identity, check required inputs and
        // unit acceptability against the same KBS catalog the job itself will use,
        // BEFORE ever dispatching a queued job. This is what lets an ambiguous label
        // or an unsupported unit surface immediately instead of after a round trip
        // through the queue.
        try {
            $preflight = $this->kbsClient->validate(
                $this->requestMapper->mapForPreflight($report->test_category->value, $set),
            );
        } catch (KbsException $exception) {
            throw new ApiException($exception->errorCode, $exception->getMessage(), 503);
        }
        if ($preflight['blocking']) {
            throw new ApiException(
                'ANALYSIS_INPUT_INVALID',
                'The verified input has issues that must be resolved before analysis.',
                422,
                ['issues' => $preflight['issues']],
            );
        }

        $identity = hash('sha256', implode('|', [
            $report->getKey(), $set->getKey(), $set->version, $flow->value, $metadata['ruleset_version'],
        ]));

        $analysis = DB::transaction(function () use ($report, $set, $user, $flow, $metadata, $identity): Analysis {
            Report::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            $analysis = Analysis::query()->where('identity_key', $identity)->lockForUpdate()->first();
            if ($analysis !== null && in_array($analysis->status, [AnalysisStatus::Queued, AnalysisStatus::Processing, AnalysisStatus::Succeeded], true)) {
                return $analysis;
            }
            $values = [
                'report_id' => $report->getKey(),
                'verified_result_set_id' => $set->getKey(),
                'verified_result_set_version' => $set->version,
                'user_id' => $user->getKey(),
                'report_category' => $report->test_category->value,
                'status' => AnalysisStatus::Queued,
                'flow' => $flow,
                'identity_key' => $identity,
                'schema_version' => 1,
                'input_schema_version' => 1,
                'engine_version' => $metadata['engine_version'],
                'ruleset_version' => $metadata['ruleset_version'],
                'catalog_version' => $metadata['analyte_catalog_version'],
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'duration_ms' => null,
                'error_code' => null,
                'safe_error_message' => null,
            ];
            if ($analysis === null) {
                $analysis = Analysis::query()->create($values);
            } else {
                $analysis->conclusions()->delete();
                $analysis->ruleTraces()->delete();
                $analysis->update($values);
            }

            ProcessReportAnalysis::dispatch($analysis->getKey())->afterCommit();

            return $analysis;
        }, 3);

        $analysis->refresh();

        return $analysis;
    }
}
