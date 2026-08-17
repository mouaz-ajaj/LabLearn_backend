<?php

namespace App\Services\Comparison;

use App\Enums\AnalysisStatus;
use App\Enums\ComparisonMatchBasis;
use App\Models\Analysis;
use App\Models\Report;
use App\Models\VerifiedResult;
use App\Models\VerifiedResultSet;
use App\Services\Reports\SelectHistoricalAnalysis;

/**
 * Phase 4C orchestrator: for each already-authorized, same-category, chronologically
 * ordered report, selects its latest VerifiedResultSet and (via the exact same
 * SelectHistoricalAnalysis used by Phase 4B Report Details) its representative
 * Analysis, builds a per-report analyte snapshot, and delegates cross-report trend
 * classification to CompareAnalyteSeries. Entirely read-only: no OCR/KBS rerun, no
 * Analysis/VerifiedResultSet mutation.
 */
class BuildReportComparison
{
    public function __construct(
        private readonly SelectHistoricalAnalysis $selectHistoricalAnalysis,
        private readonly CompareAnalyteSeries $compareAnalyteSeries,
        private readonly ClassifyConclusionTransitions $classifyConclusionTransitions,
    ) {}

    /**
     * @param  Report[]  $reports  oldest -> newest, already authorized + same-category
     * @return array<string, mixed>
     */
    public function handle(array $reports): array
    {
        $snapshots = [];
        $kbsTimeline = [];
        $sequence = 1;

        foreach ($reports as $report) {
            $verifiedResultSet = $report->verifiedResultSets()->with('results')->latest('version')->first();
            $analysis = $verifiedResultSet !== null
                ? $this->selectHistoricalAnalysis->forVerifiedResultSet($verifiedResultSet)
                : null;
            $analysis?->load('conclusions');

            $snapshots[] = $this->buildSnapshot($report, $sequence, $verifiedResultSet, $analysis);
            $kbsTimeline[] = $this->buildKbsTimelineEntry($report, $sequence, $analysis);
            $sequence++;
        }

        return [
            'category' => $reports[0]->test_category->value,
            'generated_at' => now()->toISOString(),
            'reports' => array_map(fn (array $snapshot): array => [
                'id' => $snapshot['report_id'],
                'sequence' => $snapshot['sequence'],
                'date' => $snapshot['date'],
                'status' => $snapshot['status'],
                'verified_result_set_version' => $snapshot['verified_result_set_version'],
                'analysis_id' => $snapshot['analysis']?->getKey(),
                'analysis_status' => $snapshot['analysis']?->status->value,
            ], $snapshots),
            'analytes' => $this->compareAnalyteSeries->handle($snapshots),
            'kbs_timeline' => $kbsTimeline,
            'pattern_transitions' => $this->classifyConclusionTransitions->handle($kbsTimeline),
        ];
    }

    /** @return array{report_id:int, sequence:int, date:?string, status:string, verified_result_set_id:?int, verified_result_set_version:?int, analysis:?Analysis, analytes: array<string, array>} */
    private function buildSnapshot(Report $report, int $sequence, ?VerifiedResultSet $verifiedResultSet, ?Analysis $analysis): array
    {
        $normalizedBySourceId = [];
        if ($analysis !== null && $analysis->status === AnalysisStatus::Succeeded) {
            foreach ($analysis->normalized_results_json ?? [] as $row) {
                if (isset($row['source_id'])) {
                    $normalizedBySourceId[(string) $row['source_id']] = $row;
                }
            }
        }

        $analytes = [];
        foreach ($verifiedResultSet?->results ?? [] as $verifiedResult) {
            $normalized = $normalizedBySourceId[(string) $verifiedResult->getKey()] ?? null;
            $resolved = $this->resolveAnalyteIdentity($verifiedResult, $normalized);
            if ($resolved === null) {
                // No KBS-validated identity and no OCR hint - this row cannot be
                // reliably cross-referenced against any other report's rows, so it is
                // simply excluded from the comparable-analyte set (never fuzzy-matched
                // by free-text label).
                continue;
            }

            $analytes[$resolved['analyte_id']] = $resolved;
        }

        return [
            'report_id' => $report->getKey(),
            'sequence' => $sequence,
            'date' => $report->created_at?->toISOString(),
            'status' => $report->status->value,
            'verified_result_set_id' => $verifiedResultSet?->getKey(),
            'verified_result_set_version' => $verifiedResultSet?->version,
            'analysis' => $analysis,
            'analytes' => $analytes,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $normalized
     * @return array{analyte_id:string, value:?float, raw_value:mixed, unit:?string, display_name:string, reference_low:?float, reference_high:?float, match_basis: ComparisonMatchBasis, verified_result_id:int}|null
     */
    private function resolveAnalyteIdentity(VerifiedResult $verifiedResult, ?array $normalized): ?array
    {
        $analyteId = is_array($normalized) && is_string($normalized['analyte_id'] ?? null) && $normalized['analyte_id'] !== ''
            ? $normalized['analyte_id']
            : null;

        if ($analyteId !== null) {
            $referenceRange = is_array($normalized['reference_range'] ?? null) ? $normalized['reference_range'] : null;

            return [
                'analyte_id' => $analyteId,
                'value' => is_numeric($normalized['value'] ?? null) ? (float) $normalized['value'] : null,
                'raw_value' => $verifiedResult->value,
                'unit' => is_string($normalized['unit'] ?? null) && $normalized['unit'] !== '' ? $normalized['unit'] : $verifiedResult->unit,
                'display_name' => is_string($normalized['display_name'] ?? null) && $normalized['display_name'] !== '' ? $normalized['display_name'] : $verifiedResult->label,
                'display_name_ar' => is_string($normalized['display_name_ar'] ?? null) && $normalized['display_name_ar'] !== '' ? $normalized['display_name_ar'] : null,
                'reference_low' => $referenceRange !== null && is_numeric($referenceRange['low'] ?? null) ? (float) $referenceRange['low'] : null,
                'reference_high' => $referenceRange !== null && is_numeric($referenceRange['high'] ?? null) ? (float) $referenceRange['high'] : null,
                'match_basis' => ComparisonMatchBasis::KbsAnalyteId,
                'verified_result_id' => $verifiedResult->getKey(),
            ];
        }

        if ($verifiedResult->canonical_analyte_id_hint !== null && $verifiedResult->canonical_analyte_id_hint !== '') {
            return [
                'analyte_id' => $verifiedResult->canonical_analyte_id_hint,
                'value' => is_numeric($verifiedResult->value) ? (float) $verifiedResult->value : null,
                'raw_value' => $verifiedResult->value,
                'unit' => $verifiedResult->unit,
                'display_name' => $verifiedResult->label,
                'display_name_ar' => null,
                'reference_low' => null,
                'reference_high' => null,
                'match_basis' => ComparisonMatchBasis::OcrHint,
                'verified_result_id' => $verifiedResult->getKey(),
            ];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function buildKbsTimelineEntry(Report $report, int $sequence, ?Analysis $analysis): array
    {
        $succeeded = $analysis !== null && $analysis->status === AnalysisStatus::Succeeded;

        return [
            'report_id' => $report->getKey(),
            'sequence' => $sequence,
            'analysis_id' => $analysis?->getKey(),
            'analysis_status' => $analysis?->status->value,
            'conclusions' => $succeeded
                ? $analysis->conclusions->map(fn ($conclusion): array => [
                    'code' => $conclusion->conclusion_code,
                    'level' => $conclusion->level,
                    'title' => $conclusion->title_json,
                    'summary' => $conclusion->summary_json,
                    'rule_codes' => $conclusion->rule_codes_json,
                ])->values()->all()
                : [],
            'missing_information' => $succeeded ? ($analysis->missing_information_json ?? []) : [],
        ];
    }
}
