<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\AnalysisConclusion;
use App\Models\RuleTrace;
use App\Services\Kbs\KbsLocalizationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Localization-ONLY backfill for historical SUCCEEDED analyses whose
 * `analysis_conclusions.title_json`/`summary_json` (and evidence/analyte display
 * labels) were persisted before the KBS localization repair - some of which
 * genuinely contain English prose silently stored under the `ar` key (the
 * confirmed root-cause bug in the old `report_builder.py` why_ar fallback), and
 * some of which simply have `ar: null` where translated content now exists.
 *
 * This is explicitly NOT a medical re-analysis: it never reruns KBS, never
 * changes `rule_codes`/`conclusion_codes`/analyte values/units/statuses/analysis
 * status, and never touches `verified_result_set_id` or the analysis's original
 * `started_at`/`completed_at`/`created_at` timestamps. It only repairs
 * presentation-layer Arabic text, looked up from the CURRENT (already-corrected)
 * KBS knowledge_base JSON files by stable identifier (conclusion_code / rule_code
 * / analyte_id) - never by fuzzy or free-text matching. A row that cannot be
 * safely mapped is skipped and reported, never guessed.
 *
 * Safe by construction:
 *  - Dry-run by default; --apply is required to write anything.
 *  - Each chunk of work is wrapped in its own DB transaction - a failure rolls
 *    back that chunk only, previously-committed chunks are unaffected.
 *  - Idempotent - a repaired row no longer matches the "needs repair" condition,
 *    so re-running reports it as already-correct rather than repairing it again.
 */
class RepairLocalizedAnalysisContent extends Command
{
    protected $signature = 'kbs:repair-localized-analysis-content
        {--apply : Actually write the repairs. Without this flag the command only reports what it would do.}
        {--dry-run : Explicit alias for the default (no-write) behavior; accepted for script clarity.}
        {--chunk=200 : Number of analysis_conclusions rows processed per transaction.}';

    protected $description = 'Backfill Arabic title/summary/evidence-label text on historical SUCCEEDED analyses from the current (corrected) KBS catalog - localization only, never a medical re-analysis.';

    private KbsLocalizationCatalog $catalog;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->catalog = new KbsLocalizationCatalog((string) config('quiz.kbs_knowledge_base_path'));

        $this->info('KBS Historical Localization Repair');
        $this->line($apply ? 'Mode: APPLY (writes will be made)' : 'Mode: DRY-RUN (no writes - pass --apply to write)');
        $this->newLine();

        $summary = [
            'analyses_scanned' => 0,
            'conclusions_scanned' => 0,
            'conclusions_affected' => 0,
            'titles_repaired' => 0,
            'summaries_repaired' => 0,
            'conclusion_evidence_labels_repaired' => 0,
            'rule_trace_evidence_labels_repaired' => 0,
            'normalized_result_display_names_repaired' => 0,
            'rows_skipped_already_correct' => 0,
            'rows_skipped_unrepairable' => 0,
            'errors' => 0,
        ];

        $analysisIds = Analysis::query()
            ->where('status', AnalysisStatus::Succeeded)
            ->orderBy('id')
            ->pluck('id');
        $summary['analyses_scanned'] = $analysisIds->count();

        $analysisIds->chunk($chunkSize)->each(function ($chunk) use ($apply, &$summary): void {
            try {
                $chunkResult = DB::transaction(fn () => $this->repairChunk($chunk->all(), $apply));
                foreach ($chunkResult as $key => $value) {
                    $summary[$key] += $value;
                }
            } catch (Throwable $exception) {
                $summary['errors']++;
                $this->error('Chunk failed and was rolled back: '.$exception->getMessage());
            }
        });

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if (! $apply) {
            $this->newLine();
            $this->info('This was a dry run - no rows were changed. Re-run with --apply to write these repairs.');
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  int[]  $analysisIds
     * @return array<string, int>
     */
    private function repairChunk(array $analysisIds, bool $apply): array
    {
        $result = [
            'conclusions_scanned' => 0, 'conclusions_affected' => 0, 'titles_repaired' => 0,
            'summaries_repaired' => 0, 'conclusion_evidence_labels_repaired' => 0,
            'rule_trace_evidence_labels_repaired' => 0, 'normalized_result_display_names_repaired' => 0,
            'rows_skipped_already_correct' => 0, 'rows_skipped_unrepairable' => 0,
        ];

        foreach (AnalysisConclusion::query()->whereIn('analysis_id', $analysisIds)->get() as $conclusion) {
            $result['conclusions_scanned']++;
            $affected = false;

            [$title, $titleOutcome] = $this->repairLocalizedText(
                $conclusion->title_json,
                fn () => $this->catalog->conditionNameAr($conclusion->conclusion_code),
            );
            if ($titleOutcome === 'repaired') {
                $result['titles_repaired']++;
                $affected = true;
            } else {
                $result[$titleOutcome === 'already_correct' ? 'rows_skipped_already_correct' : 'rows_skipped_unrepairable']++;
            }

            [$summaryJson, $summaryOutcome] = $this->repairLocalizedText(
                $conclusion->summary_json,
                fn () => $this->catalog->reconstructedSummaryAr($conclusion->rule_codes_json ?? []),
            );
            if ($summaryOutcome === 'repaired') {
                $result['summaries_repaired']++;
                $affected = true;
            } else {
                $result[$summaryOutcome === 'already_correct' ? 'rows_skipped_already_correct' : 'rows_skipped_unrepairable']++;
            }

            [$evidence, $evidenceRepairedCount] = $this->repairEvidenceLabels($conclusion->evidence_json ?? []);
            if ($evidenceRepairedCount > 0) {
                $result['conclusion_evidence_labels_repaired'] += $evidenceRepairedCount;
                $affected = true;
            }

            if ($affected) {
                $result['conclusions_affected']++;
                if ($apply) {
                    $conclusion->forceFill([
                        'title_json' => $title,
                        'summary_json' => $summaryJson,
                        'evidence_json' => $evidence,
                    ])->save();
                }
            }
        }

        foreach (RuleTrace::query()->whereIn('analysis_id', $analysisIds)->get() as $trace) {
            [$evidence, $repairedCount] = $this->repairEvidenceLabels($trace->evidence_json ?? []);
            if ($repairedCount > 0) {
                $result['rule_trace_evidence_labels_repaired'] += $repairedCount;
                if ($apply) {
                    $trace->forceFill(['evidence_json' => $evidence])->save();
                }
            }
        }

        foreach (Analysis::query()->whereIn('id', $analysisIds)->get() as $analysis) {
            [$normalizedResults, $repairedCount] = $this->repairNormalizedResultDisplayNames($analysis->normalized_results_json ?? []);
            if ($repairedCount > 0) {
                $result['normalized_result_display_names_repaired'] += $repairedCount;
                if ($apply) {
                    // Only the display_name_ar sibling field changes here - analyte_id,
                    // value, unit, status, reference_range, and every other medical
                    // fact in this array is passed through byte-for-byte unchanged.
                    $analysis->forceFill(['normalized_results_json' => $normalizedResults])->save();
                }
            }
        }

        return $result;
    }

    /**
     * @param  array{en?: string, ar?: string}|null  $localizedText
     * @return array{0: array{en?: string, ar?: string}|null, 1: 'repaired'|'already_correct'|'unrepairable'}
     */
    private function repairLocalizedText(?array $localizedText, \Closure $lookupArabic): array
    {
        if (! is_array($localizedText)) {
            return [$localizedText, 'unrepairable'];
        }

        $ar = $localizedText['ar'] ?? null;
        $isMissing = ! is_string($ar) || $ar === '';
        // The confirmed historical bug (report_builder.py's why_ar fallback) does
        // not necessarily copy `en` verbatim into `ar` - it can assemble a
        // *different* English sentence (joined rule explanations) under the `ar`
        // key while `en` holds the condition's own English description. The
        // reliable signal is therefore "ar contains zero Arabic-script
        // characters", not "ar equals en byte-for-byte".
        $isMislabeledAsEnglish = is_string($ar) && $ar !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $ar) === 0;

        if (! $isMissing && ! $isMislabeledAsEnglish) {
            return [$localizedText, 'already_correct'];
        }

        $candidate = $lookupArabic();
        if (! is_string($candidate) || $candidate === '') {
            return [$localizedText, 'unrepairable'];
        }

        return [[...$localizedText, 'ar' => $candidate], 'repaired'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function repairEvidenceLabels(array $evidence): array
    {
        $repairedCount = 0;
        $result = array_map(function (array $item) use (&$repairedCount): array {
            $labelAr = $item['label_ar'] ?? null;
            $analyteId = $item['analyte_id'] ?? null;
            if ((is_string($labelAr) && $labelAr !== '') || ! is_string($analyteId) || $analyteId === '') {
                return $item;
            }
            $candidate = $this->catalog->analyteNameAr($analyteId);
            if (! is_string($candidate) || $candidate === '') {
                return $item;
            }
            $repairedCount++;

            return [...$item, 'label_ar' => $candidate];
        }, $evidence);

        return [$result, $repairedCount];
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalizedResults
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function repairNormalizedResultDisplayNames(array $normalizedResults): array
    {
        $repairedCount = 0;
        $result = array_map(function (array $row) use (&$repairedCount): array {
            $displayNameAr = $row['display_name_ar'] ?? null;
            $analyteId = $row['analyte_id'] ?? null;
            if ((is_string($displayNameAr) && $displayNameAr !== '') || ! is_string($analyteId) || $analyteId === '') {
                return $row;
            }
            $candidate = $this->catalog->analyteNameAr($analyteId);
            if (! is_string($candidate) || $candidate === '') {
                return $row;
            }
            $repairedCount++;

            return [...$row, 'display_name_ar' => $candidate];
        }, $normalizedResults);

        return [$result, $repairedCount];
    }
}
