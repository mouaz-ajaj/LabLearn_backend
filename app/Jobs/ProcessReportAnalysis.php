<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Enums\QuizSessionStatus;
use App\Enums\ReportStatus;
use App\Models\Analysis;
use App\Models\QuizSession;
use App\Models\Report;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsException;
use App\Services\Kbs\KbsRequestMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessReportAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $analysisId) {}

    public function uniqueId(): string
    {
        return (string) $this->analysisId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 20, 60];
    }

    public function handle(KbsClient $client, KbsRequestMapper $mapper): void
    {
        $analysis = Analysis::query()->findOrFail($this->analysisId);
        if ($analysis->status === AnalysisStatus::Succeeded) {
            return;
        }
        $started = hrtime(true);
        $analysis->update([
            'status' => AnalysisStatus::Processing,
            'started_at' => $analysis->started_at ?? now(),
            'failed_at' => null,
            'attempt_count' => max($analysis->attempt_count + 1, $this->attempts()),
            'error_code' => null,
            'safe_error_message' => null,
        ]);

        try {
            $payload = $client->analyze($mapper->map($analysis), $analysis);
            $duration = (int) round((hrtime(true) - $started) / 1_000_000);
            DB::transaction(function () use ($analysis, $payload, $duration): void {
                $locked = Analysis::query()->whereKey($analysis->getKey())->lockForUpdate()->firstOrFail();
                $locked->conclusions()->delete();
                $locked->ruleTraces()->delete();
                foreach ($payload['conclusions'] as $index => $conclusion) {
                    $locked->conclusions()->create([
                        'conclusion_code' => $conclusion['code'],
                        'level' => $conclusion['level'],
                        'title_json' => $conclusion['title'],
                        'summary_json' => $conclusion['summary'],
                        'evidence_json' => $conclusion['evidence'],
                        'rule_codes_json' => $conclusion['rule_codes'],
                        'display_order' => $index + 1,
                    ]);
                }
                foreach ($payload['rule_traces'] as $trace) {
                    $locked->ruleTraces()->create([
                        'rule_code' => $trace['rule_code'],
                        'rule_version' => (string) $trace['rule_version'],
                        'fired' => $trace['fired'],
                        'conditions_json' => $trace['conditions'],
                        'evidence_json' => $trace['evidence'],
                        'conclusion_codes_json' => $trace['conclusion_codes'],
                    ]);
                }
                $locked->update([
                    'status' => AnalysisStatus::Succeeded,
                    'schema_version' => (int) $payload['schema_version'],
                    'input_schema_version' => (int) $payload['input_schema_version'],
                    'engine_version' => $payload['engine_version'],
                    'ruleset_version' => $payload['ruleset_version'],
                    'catalog_version' => $payload['analyte_catalog_version'],
                    'completed_at' => now(),
                    'failed_at' => null,
                    'duration_ms' => $duration,
                    'error_code' => null,
                    'safe_error_message' => null,
                    'normalized_results_json' => $payload['normalized_results'],
                    'facts_json' => $payload['facts'],
                    'missing_information_json' => $payload['missing_information'],
                    'warnings_json' => $payload['warnings'],
                    'disclaimer_json' => $payload['disclaimer'],
                    'summary_json' => $payload['summary'],
                    'raw_kbs_response_json' => $payload,
                ]);
                // Direct-result has no separate "delivery" step distinct from the analysis
                // succeeding, so the report is marked complete here rather than left at the
                // intermediate ANALYZED status waiting on a later read to flip it (a GET must
                // stay side-effect-free).
                Report::query()->whereKey($locked->report_id)->update(['status' => ReportStatus::Completed]);
            }, 3);
            $this->dispatchPendingQuizFinalizations();
        } catch (KbsException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }
            $this->markFailed($exception->errorCode, $exception->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            $exception instanceof KbsException ? $exception->errorCode : 'KBS_ANALYSIS_FAILED',
            $exception instanceof KbsException ? $exception->getMessage() : 'The analysis could not be completed. Please try again.',
        );
    }

    private function markFailed(string $code, string $message): void
    {
        Analysis::query()->whereKey($this->analysisId)->update([
            'status' => AnalysisStatus::Failed,
            'failed_at' => now(),
            'error_code' => $code,
            'safe_error_message' => $message,
        ]);
        $this->dispatchPendingQuizFinalizations();
    }

    /**
     * Any quiz session that was left PREPARING because it was waiting on this exact
     * Analysis (see StartQuizSession) can now be finished — successfully with real
     * Case-Specific evidence, or as a General-only/failed quiz if the analysis itself
     * failed. A quiz session only reaches this state via the async (queued) path; the
     * common warm-cache path is finalized synchronously in StartQuizSession and never
     * dispatches this job at all.
     */
    private function dispatchPendingQuizFinalizations(): void
    {
        QuizSession::query()
            ->where('analysis_id', $this->analysisId)
            ->where('status', QuizSessionStatus::Preparing)
            ->pluck('id')
            ->each(fn (int $quizSessionId) => FinalizeQuizPreparation::dispatch($quizSessionId)->afterCommit());
    }
}
