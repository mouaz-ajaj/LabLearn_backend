<?php

namespace App\Services\Comparison;

use App\Enums\LabMovementClassification;
use App\Enums\ReferenceStatus;
use App\Enums\ReferenceTrend;

/**
 * Pure, stateless reference-interval logic. Only ever called with numeric bounds
 * already sourced from a succeeded Analysis's normalized_results_json (see
 * CompareAnalyteSeries) - this class never parses free-text reference ranges and
 * never invents a reference interval.
 */
class ReferenceIntervalComparison
{
    public function status(float $value, ?float $low, ?float $high): ReferenceStatus
    {
        if ($low === null || $high === null) {
            return ReferenceStatus::Unknown;
        }
        if ($value < $low) {
            return ReferenceStatus::Below;
        }
        if ($value > $high) {
            return ReferenceStatus::Above;
        }

        return ReferenceStatus::Within;
    }

    /** Distance from the reference interval; 0 when within it. */
    private function distance(float $value, ReferenceStatus $status, ?float $low, ?float $high): float
    {
        return match ($status) {
            ReferenceStatus::Below => $low !== null ? $low - $value : 0.0,
            ReferenceStatus::Above => $high !== null ? $value - $high : 0.0,
            default => 0.0,
        };
    }

    /**
     * Directional relationship between the earliest and latest comparable reference
     * status for one analyte track. A direction flip (below -> above the interval, or
     * vice versa) is intentionally reported as Unknown - "closer/farther" is ambiguous
     * once the value has crossed all the way through the interval and out the other side.
     */
    public function trend(
        float $earliestValue,
        ReferenceStatus $earliestStatus,
        ?float $earliestLow,
        ?float $earliestHigh,
        float $latestValue,
        ReferenceStatus $latestStatus,
        ?float $latestLow,
        ?float $latestHigh,
    ): ReferenceTrend {
        if ($earliestStatus === ReferenceStatus::Unknown || $latestStatus === ReferenceStatus::Unknown) {
            return ReferenceTrend::Unknown;
        }
        if ($earliestStatus === ReferenceStatus::Within && $latestStatus === ReferenceStatus::Within) {
            return ReferenceTrend::RemainedWithin;
        }
        if ($earliestStatus === ReferenceStatus::Within && $latestStatus !== ReferenceStatus::Within) {
            return ReferenceTrend::MovedFarther;
        }
        if ($earliestStatus !== ReferenceStatus::Within && $latestStatus === ReferenceStatus::Within) {
            return ReferenceTrend::MovedCloser;
        }
        if ($earliestStatus !== $latestStatus) {
            // Below -> Above or Above -> Below: crossed all the way through the interval.
            return ReferenceTrend::Unknown;
        }

        $earliestDistance = $this->distance($earliestValue, $earliestStatus, $earliestLow, $earliestHigh);
        $latestDistance = $this->distance($latestValue, $latestStatus, $latestLow, $latestHigh);
        $tolerance = max(abs($earliestDistance), abs($latestDistance), 1.0) * 0.001;

        if (abs($latestDistance - $earliestDistance) <= $tolerance) {
            return ReferenceTrend::RemainedOutside;
        }

        return $latestDistance < $earliestDistance ? ReferenceTrend::MovedCloser : ReferenceTrend::MovedFarther;
    }

    /**
     * The higher-level "did this actually normalize, or just move in a better
     * direction while still abnormal" classification (see LabMovementClassification's
     * own docblock for the exact product distinction). Derived purely from the
     * earliest/latest reference status plus the already-computed ReferenceTrend -
     * no new numeric comparison or clinical threshold is introduced here.
     */
    public function labMovementClassification(
        ReferenceStatus $earliestStatus,
        ReferenceStatus $latestStatus,
        ReferenceTrend $referenceTrend,
    ): LabMovementClassification {
        if ($referenceTrend === ReferenceTrend::Unknown) {
            return LabMovementClassification::ReferenceStatusUnknown;
        }
        if ($earliestStatus === ReferenceStatus::Within && $latestStatus === ReferenceStatus::Within) {
            return LabMovementClassification::RemainedWithinReference;
        }
        if ($earliestStatus === ReferenceStatus::Within) {
            // $latestStatus !== Within, since both-Within was already handled above.
            return LabMovementClassification::BecameAbnormal;
        }
        if ($latestStatus === ReferenceStatus::Within) {
            // $earliestStatus !== Within, since both-Within was already handled above.
            return LabMovementClassification::Normalized;
        }

        // Neither point is within the reference range - still abnormal at both ends.
        return match ($referenceTrend) {
            ReferenceTrend::MovedCloser => LabMovementClassification::MovedCloserButStillAbnormal,
            ReferenceTrend::MovedFarther => LabMovementClassification::MovedFartherAndStillAbnormal,
            ReferenceTrend::RemainedOutside => LabMovementClassification::PersistentAbnormalWithoutMeaningfulMovement,
            default => LabMovementClassification::ReferenceStatusUnknown,
        };
    }
}
