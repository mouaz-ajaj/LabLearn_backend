<?php

namespace App\Enums;

/**
 * Deterministic classification of one KBS conclusion_code's presence across the
 * chronologically-ordered reports in a comparison, computed by
 * App\Services\Comparison\ClassifyConclusionTransitions - never decided by Gemini.
 * "Earliest"/"latest" always mean the earliest/latest report that has a SUCCEEDED
 * Analysis in this comparison, not necessarily the first/last report in the request.
 */
enum ConclusionTransition: string
{
    /** Present in the earliest succeeded analysis, still present in the latest. */
    case Persisted = 'PERSISTED';

    /** Present in the earliest succeeded analysis, absent from the latest. */
    case Disappeared = 'DISAPPEARED';

    /** Absent from the earliest succeeded analysis, present in the latest. */
    case Appeared = 'APPEARED';

    /** Absent from both the earliest and latest succeeded analyses, but present in at least one report strictly between them (only possible with 3+ reports). */
    case Transient = 'TRANSIENT';
}
