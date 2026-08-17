<?php

use App\Enums\LabMovementClassification;
use App\Enums\ReferenceStatus;
use App\Enums\ReferenceTrend;
use App\Services\Comparison\ReferenceIntervalComparison;

/**
 * Locks the exact deterministic classification matrix the Phase 4C comparison
 * redesign product-requires - especially the critical distinction that a value
 * moving from LOW to "less LOW" (still outside the reference range) must NEVER be
 * classified as Normalized, only as MovedCloserButStillAbnormal.
 */
function labMovement(ReferenceStatus $earliest, ReferenceStatus $latest, ReferenceTrend $trend): LabMovementClassification
{
    return (new ReferenceIntervalComparison)->labMovementClassification($earliest, $latest, $trend);
}

test('LOW to WITHIN is Normalized', function () {
    expect(labMovement(ReferenceStatus::Below, ReferenceStatus::Within, ReferenceTrend::MovedCloser))
        ->toBe(LabMovementClassification::Normalized);
});

test('HIGH to WITHIN is Normalized', function () {
    expect(labMovement(ReferenceStatus::Above, ReferenceStatus::Within, ReferenceTrend::MovedCloser))
        ->toBe(LabMovementClassification::Normalized);
});

test('CRITICAL REGRESSION LOCK: LOW to less-LOW (HGB 8.5 -> 9.5, both below reference) is MovedCloserButStillAbnormal, never Normalized', function () {
    $result = labMovement(ReferenceStatus::Below, ReferenceStatus::Below, ReferenceTrend::MovedCloser);

    expect($result)->toBe(LabMovementClassification::MovedCloserButStillAbnormal)
        ->and($result)->not->toBe(LabMovementClassification::Normalized);
});

test('HIGH to less-HIGH is MovedCloserButStillAbnormal', function () {
    expect(labMovement(ReferenceStatus::Above, ReferenceStatus::Above, ReferenceTrend::MovedCloser))
        ->toBe(LabMovementClassification::MovedCloserButStillAbnormal);
});

test('WITHIN to HIGH is BecameAbnormal', function () {
    expect(labMovement(ReferenceStatus::Within, ReferenceStatus::Above, ReferenceTrend::MovedFarther))
        ->toBe(LabMovementClassification::BecameAbnormal);
});

test('WITHIN to LOW is BecameAbnormal', function () {
    expect(labMovement(ReferenceStatus::Within, ReferenceStatus::Below, ReferenceTrend::MovedFarther))
        ->toBe(LabMovementClassification::BecameAbnormal);
});

test('HIGH to farther-HIGH is MovedFartherAndStillAbnormal', function () {
    expect(labMovement(ReferenceStatus::Above, ReferenceStatus::Above, ReferenceTrend::MovedFarther))
        ->toBe(LabMovementClassification::MovedFartherAndStillAbnormal);
});

test('LOW to farther-LOW is MovedFartherAndStillAbnormal', function () {
    expect(labMovement(ReferenceStatus::Below, ReferenceStatus::Below, ReferenceTrend::MovedFarther))
        ->toBe(LabMovementClassification::MovedFartherAndStillAbnormal);
});

test('WITHIN to WITHIN is RemainedWithinReference', function () {
    expect(labMovement(ReferenceStatus::Within, ReferenceStatus::Within, ReferenceTrend::RemainedWithin))
        ->toBe(LabMovementClassification::RemainedWithinReference);
});

test('abnormal and numerically stable (RemainedOutside) is PersistentAbnormalWithoutMeaningfulMovement, distinct from MovedCloser/MovedFarther', function () {
    $result = labMovement(ReferenceStatus::Below, ReferenceStatus::Below, ReferenceTrend::RemainedOutside);

    expect($result)->toBe(LabMovementClassification::PersistentAbnormalWithoutMeaningfulMovement)
        ->and($result)->not->toBe(LabMovementClassification::MovedCloserButStillAbnormal)
        ->and($result)->not->toBe(LabMovementClassification::MovedFartherAndStillAbnormal);
});

test('a missing reference bound (Unknown status) yields ReferenceStatusUnknown', function () {
    expect(labMovement(ReferenceStatus::Unknown, ReferenceStatus::Below, ReferenceTrend::Unknown))
        ->toBe(LabMovementClassification::ReferenceStatusUnknown)
        ->and(labMovement(ReferenceStatus::Below, ReferenceStatus::Unknown, ReferenceTrend::Unknown))
        ->toBe(LabMovementClassification::ReferenceStatusUnknown);
});

test('a below-to-above crossing (ReferenceTrend::Unknown) yields ReferenceStatusUnknown, not a guessed direction', function () {
    expect(labMovement(ReferenceStatus::Below, ReferenceStatus::Above, ReferenceTrend::Unknown))
        ->toBe(LabMovementClassification::ReferenceStatusUnknown);
});
