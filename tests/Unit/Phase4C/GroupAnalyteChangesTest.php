<?php

use App\Services\Comparison\GroupAnalyteChanges;

function groupableAnalyte(string $id, string $classification, bool $comparable = true): array
{
    return [
        'analyte_id' => $id, 'display_name' => $id, 'display_name_ar' => null, 'unit' => 'g/dL', 'comparable' => $comparable,
        'points' => [
            ['report_id' => 1, 'sequence' => 1, 'value' => 1.0, 'raw_value' => '1.0', 'unit' => 'g/dL', 'reference_status' => 'BELOW_REFERENCE', 'reference_low' => 2.0, 'reference_high' => 5.0, 'match_basis' => 'KBS_ANALYTE_ID'],
            ['report_id' => 2, 'sequence' => 2, 'value' => 1.5, 'raw_value' => '1.5', 'unit' => 'g/dL', 'reference_status' => 'BELOW_REFERENCE', 'reference_low' => 2.0, 'reference_high' => 5.0, 'match_basis' => 'KBS_ANALYTE_ID'],
        ],
        'trend' => 'INCREASED', 'reference_trend' => 'MOVED_CLOSER_TO_REFERENCE',
        'lab_change_classification' => $classification, 'earliest_status' => 'BELOW_REFERENCE', 'latest_status' => 'BELOW_REFERENCE',
    ];
}

test('each classification lands in exactly one section', function () {
    $analytes = [
        groupableAnalyte('a', 'NORMALIZED'),
        groupableAnalyte('b', 'MOVED_CLOSER_BUT_STILL_ABNORMAL'),
        groupableAnalyte('c', 'BECAME_ABNORMAL'),
        groupableAnalyte('d', 'MOVED_FARTHER_AND_STILL_ABNORMAL'),
        groupableAnalyte('e', 'PERSISTENT_ABNORMAL_WITHOUT_MEANINGFUL_REFERENCE_MOVEMENT'),
    ];

    $grouped = (new GroupAnalyteChanges)->handle($analytes);

    expect(array_column($grouped['normalized'], 'analyte_id'))->toBe(['a'])
        ->and(array_column($grouped['better_but_still_abnormal'], 'analyte_id'))->toBe(['b'])
        ->and(array_column($grouped['new_or_worse'], 'analyte_id'))->toBe(['c', 'd'])
        ->and(array_column($grouped['persistent_abnormal'], 'analyte_id'))->toBe(['e']);
});

test('REMAINED_WITHIN_REFERENCE is counted, not listed in any section', function () {
    $analytes = [groupableAnalyte('a', 'REMAINED_WITHIN_REFERENCE'), groupableAnalyte('b', 'REMAINED_WITHIN_REFERENCE')];

    $grouped = (new GroupAnalyteChanges)->handle($analytes);

    expect($grouped['unchanged_comparable_count'])->toBe(2)
        ->and($grouped['normalized'])->toBe([])
        ->and($grouped['better_but_still_abnormal'])->toBe([])
        ->and($grouped['new_or_worse'])->toBe([])
        ->and($grouped['persistent_abnormal'])->toBe([]);
});

test('INSUFFICIENT_DATA / NOT_COMPARABLE / REFERENCE_STATUS_UNKNOWN never appear in any section or the unchanged count', function () {
    $analytes = [
        groupableAnalyte('a', 'INSUFFICIENT_DATA'),
        groupableAnalyte('b', 'NOT_COMPARABLE'),
        groupableAnalyte('c', 'REFERENCE_STATUS_UNKNOWN'),
    ];

    $grouped = (new GroupAnalyteChanges)->handle($analytes);

    expect($grouped['normalized'])->toBe([])
        ->and($grouped['better_but_still_abnormal'])->toBe([])
        ->and($grouped['new_or_worse'])->toBe([])
        ->and($grouped['persistent_abnormal'])->toBe([])
        ->and($grouped['unchanged_comparable_count'])->toBe(0);
});

test('a non-comparable analyte is excluded even if it happens to carry a classification value', function () {
    $analytes = [groupableAnalyte('a', 'NORMALIZED', comparable: false)];

    $grouped = (new GroupAnalyteChanges)->handle($analytes);

    expect($grouped['normalized'])->toBe([]);
});
