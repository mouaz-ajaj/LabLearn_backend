<?php

namespace App\Services\Comparison;

use App\Enums\ComparisonTrend;
use App\Enums\LabMovementClassification;
use App\Enums\ReferenceStatus;
use App\Enums\ReferenceTrend;

/**
 * Groups per-report analyte snapshots (built by BuildReportComparison) into
 * cross-report analyte tracks and classifies each track's deterministic trend.
 *
 * Trend is always earliest-comparable-point vs latest-comparable-point (the reports
 * are already chronologically ordered upstream) - not a full monotonic-series check.
 * This matches the feature's own framing: a single directional fact from the oldest
 * to the newest selected report, not a multi-point regression.
 */
class CompareAnalyteSeries
{
    public function __construct(private readonly ReferenceIntervalComparison $referenceComparison) {}

    /**
     * @param  array<int, array{report_id:int, sequence:int, analytes: array<string, array>}>  $snapshots
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $snapshots): array
    {
        $identities = [];
        $identitiesAr = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['analytes'] as $id => $entry) {
                if (! isset($identities[$id])) {
                    $identities[$id] = $entry['display_name'];
                    $identitiesAr[$id] = $entry['display_name_ar'] ?? null;
                }
            }
        }

        $tracks = [];
        foreach ($identities as $analyteId => $displayName) {
            $tracks[] = $this->buildTrack((string) $analyteId, $displayName, $identitiesAr[$analyteId] ?? null, $snapshots);
        }

        return $tracks;
    }

    /** @param  array<int, array{report_id:int, sequence:int, analytes: array<string, array>}>  $snapshots */
    private function buildTrack(string $analyteId, string $displayName, ?string $displayNameAr, array $snapshots): array
    {
        $points = [];
        $units = [];
        $representativeUnit = null;

        foreach ($snapshots as $snapshot) {
            $entry = $snapshot['analytes'][$analyteId] ?? null;
            $value = $entry['value'] ?? null;
            $unit = $entry['unit'] ?? null;
            $low = $entry['reference_low'] ?? null;
            $high = $entry['reference_high'] ?? null;
            $status = $value !== null
                ? $this->referenceComparison->status($value, $low, $high)
                : ReferenceStatus::Unknown;

            if ($value !== null && $unit !== null) {
                $units[$unit] = true;
                $representativeUnit ??= $unit;
            }

            $points[] = [
                'report_id' => $snapshot['report_id'],
                'sequence' => $snapshot['sequence'],
                'value' => $value,
                'raw_value' => $entry['raw_value'] ?? null,
                'unit' => $unit,
                'reference_status' => $status,
                'reference_low' => $low,
                'reference_high' => $high,
                'match_basis' => $entry['match_basis'] ?? null,
            ];
        }

        $comparablePoints = array_values(array_filter($points, fn (array $p): bool => $p['value'] !== null));
        $consistentUnits = count($units) <= 1;
        $comparable = $consistentUnits && count($comparablePoints) >= 2;

        $earliestStatusForClassification = ReferenceStatus::Unknown;
        $latestStatusForClassification = ReferenceStatus::Unknown;

        if (count($comparablePoints) < 2) {
            $trend = ComparisonTrend::InsufficientData;
            $referenceTrend = ReferenceTrend::Unknown;
            $labMovementClassification = LabMovementClassification::InsufficientData;
        } elseif (! $consistentUnits) {
            $trend = ComparisonTrend::NotComparable;
            $referenceTrend = ReferenceTrend::Unknown;
            $labMovementClassification = LabMovementClassification::NotComparable;
        } else {
            $earliest = $comparablePoints[0];
            $latest = $comparablePoints[count($comparablePoints) - 1];
            $earliestStatusForClassification = $earliest['reference_status'];
            $latestStatusForClassification = $latest['reference_status'];
            $trend = $this->classifyTrend((float) $earliest['value'], (float) $latest['value']);
            $referenceTrend = $earliest['reference_status'] !== ReferenceStatus::Unknown && $latest['reference_status'] !== ReferenceStatus::Unknown
                ? $this->referenceComparison->trend(
                    (float) $earliest['value'], $earliest['reference_status'], $earliest['reference_low'], $earliest['reference_high'],
                    (float) $latest['value'], $latest['reference_status'], $latest['reference_low'], $latest['reference_high'],
                )
                : ReferenceTrend::Unknown;
            $labMovementClassification = $this->referenceComparison->labMovementClassification(
                $earliestStatusForClassification,
                $latestStatusForClassification,
                $referenceTrend,
            );
        }

        return [
            'analyte_id' => $analyteId,
            'display_name' => $displayName,
            'display_name_ar' => $displayNameAr,
            'unit' => $representativeUnit,
            'comparable' => $comparable,
            'points' => array_map(fn (array $p): array => [
                'report_id' => $p['report_id'],
                'sequence' => $p['sequence'],
                'value' => $p['value'],
                'raw_value' => $p['raw_value'],
                'unit' => $p['unit'],
                'reference_status' => $p['reference_status']->value,
                'reference_low' => $p['reference_low'],
                'reference_high' => $p['reference_high'],
                'match_basis' => $p['match_basis']?->value,
            ], $points),
            'trend' => $trend->value,
            'reference_trend' => $referenceTrend->value,
            'lab_change_classification' => $labMovementClassification->value,
            'earliest_status' => $earliestStatusForClassification->value,
            'latest_status' => $latestStatusForClassification->value,
        ];
    }

    /**
     * Technical floating-point equality guard, not a clinically validated stability
     * threshold - see docs/phase-4c-comparison.md. A 0.1% relative tolerance only
     * absorbs rounding noise (e.g. unit-normalization arithmetic); any real difference
     * still classifies as increased/decreased.
     */
    private function classifyTrend(float $earliest, float $latest): ComparisonTrend
    {
        $tolerance = max(abs($earliest), abs($latest), 1.0) * 0.001;
        $delta = $latest - $earliest;
        if (abs($delta) <= $tolerance) {
            return ComparisonTrend::Stable;
        }

        return $delta > 0 ? ComparisonTrend::Increased : ComparisonTrend::Decreased;
    }
}
