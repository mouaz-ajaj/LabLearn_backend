<?php

namespace App\Enums;

/**
 * A higher-level deterministic classification of one analyte's reference-interval
 * movement between the earliest and latest comparable report, distinct from both the
 * raw numeric ComparisonTrend (increased/decreased/stable) and the directional
 * ReferenceTrend (moved closer/farther). Computed entirely by
 * ReferenceIntervalComparison::labMovementClassification() from those two already-
 * established facts - Gemini never decides this. See docs/phase-4c-comparison.md.
 *
 * The critical product distinction this enum exists to enforce: a value that went
 * from LOW to "less LOW" (still outside the reference range) must never be reported
 * as Normalized - only a value that actually crossed into the reference range earns
 * that label.
 */
enum LabMovementClassification: string
{
    /** Outside the reference range earliest, inside it latest - the strongest positive change. */
    case Normalized = 'NORMALIZED';

    /** Outside the reference range at both points, but measurably closer latest than earliest. */
    case MovedCloserButStillAbnormal = 'MOVED_CLOSER_BUT_STILL_ABNORMAL';

    /** Within the reference range earliest, outside it latest. */
    case BecameAbnormal = 'BECAME_ABNORMAL';

    /** Outside the reference range at both points, and measurably farther latest than earliest. */
    case MovedFartherAndStillAbnormal = 'MOVED_FARTHER_AND_STILL_ABNORMAL';

    /** Outside the reference range at both points, with no meaningful change in distance from it. */
    case PersistentAbnormalWithoutMeaningfulMovement = 'PERSISTENT_ABNORMAL_WITHOUT_MEANINGFUL_REFERENCE_MOVEMENT';

    /** Within the reference range at both points. */
    case RemainedWithinReference = 'REMAINED_WITHIN_REFERENCE';

    /** Either point's reference status could not be determined (no KBS-sourced bound available, or the value crossed all the way through the interval). */
    case ReferenceStatusUnknown = 'REFERENCE_STATUS_UNKNOWN';

    /** Fewer than 2 chronologically-comparable numeric points for this analyte. */
    case InsufficientData = 'INSUFFICIENT_DATA';

    /** The compared reports use inconsistent units for this analyte. */
    case NotComparable = 'NOT_COMPARABLE';
}
