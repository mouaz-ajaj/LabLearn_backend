<?php

namespace App\Services\Comparison;

use App\Enums\LabMovementClassification;

/**
 * Deterministically sorts a comparison's already-classified analyte tracks into the
 * presentation buckets the redesigned AI comparison card needs (normalized / better-
 * but-still-abnormal / new-or-worse / persistent-abnormal / unremarkable-count).
 *
 * This exists so Laravel - not Gemini - decides section membership (task requirement:
 * "Prefer Laravel deciding section membership and Gemini only generating the prose").
 * Both ComparisonContextBuilder (what gets sent to Gemini, pre-grouped) and
 * ComparisonResponseValidator (the allow-list each section is checked against) and
 * ComparisonFallbackFormatter (the deterministic no-Gemini rendering) all call this
 * same method, so the grouping can never drift between what Gemini is told and what
 * the fallback shows.
 */
class GroupAnalyteChanges
{
    /**
     * @param  array<int, array<string, mixed>>  $analytes  BuildReportComparison's comparison['analytes'], each already carrying lab_change_classification
     * @return array{normalized: array<int, array<string, mixed>>, better_but_still_abnormal: array<int, array<string, mixed>>, new_or_worse: array<int, array<string, mixed>>, persistent_abnormal: array<int, array<string, mixed>>, unchanged_comparable_count: int}
     */
    public function handle(array $analytes): array
    {
        $normalized = [];
        $betterButStillAbnormal = [];
        $newOrWorse = [];
        $persistentAbnormal = [];
        $unchangedComparableCount = 0;

        foreach ($analytes as $analyte) {
            if (! $analyte['comparable']) {
                continue;
            }

            $classification = LabMovementClassification::from($analyte['lab_change_classification']);
            $item = $this->summarize($analyte);

            match ($classification) {
                LabMovementClassification::Normalized => $normalized[] = $item,
                LabMovementClassification::MovedCloserButStillAbnormal => $betterButStillAbnormal[] = $item,
                LabMovementClassification::BecameAbnormal, LabMovementClassification::MovedFartherAndStillAbnormal => $newOrWorse[] = $item,
                LabMovementClassification::PersistentAbnormalWithoutMeaningfulMovement => $persistentAbnormal[] = $item,
                LabMovementClassification::RemainedWithinReference => $unchangedComparableCount++,
                LabMovementClassification::ReferenceStatusUnknown, LabMovementClassification::InsufficientData, LabMovementClassification::NotComparable => null,
            };
        }

        return [
            'normalized' => $normalized,
            'better_but_still_abnormal' => $betterButStillAbnormal,
            'new_or_worse' => $newOrWorse,
            'persistent_abnormal' => $persistentAbnormal,
            'unchanged_comparable_count' => $unchangedComparableCount,
        ];
    }

    /** @param array<string, mixed> $analyte */
    private function summarize(array $analyte): array
    {
        $points = $analyte['points'];
        $comparablePoints = array_values(array_filter($points, fn (array $p): bool => $p['value'] !== null));
        $earliest = $comparablePoints[0] ?? null;
        $latest = $comparablePoints[count($comparablePoints) - 1] ?? null;

        return [
            'analyte_id' => $analyte['analyte_id'],
            'display_name' => $analyte['display_name'],
            'display_name_ar' => $analyte['display_name_ar'],
            'unit' => $analyte['unit'],
            'earliest_value' => $earliest['value'] ?? null,
            'latest_value' => $latest['value'] ?? null,
            'earliest_status' => $analyte['earliest_status'],
            'latest_status' => $analyte['latest_status'],
            'trend' => $analyte['trend'],
            'reference_trend' => $analyte['reference_trend'],
            'lab_change_classification' => $analyte['lab_change_classification'],
        ];
    }
}
