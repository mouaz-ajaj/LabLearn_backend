<?php

namespace App\Enums;

/**
 * How a verified value was matched to a cross-report analyte identity (Phase 4C).
 * Analyte matching reliability is uneven across reports depending on whether each
 * has ever had a succeeded KBS analysis - this is surfaced explicitly per point
 * rather than silently normalized away.
 */
enum ComparisonMatchBasis: string
{
    case KbsAnalyteId = 'KBS_ANALYTE_ID';
    case OcrHint = 'OCR_HINT';
}
