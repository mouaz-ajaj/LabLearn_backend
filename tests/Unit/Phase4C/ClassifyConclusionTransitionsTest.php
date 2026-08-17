<?php

use App\Services\Comparison\ClassifyConclusionTransitions;

function transitionTimelineEntry(int $sequence, string $status, array $codes): array
{
    return [
        'report_id' => $sequence, 'sequence' => $sequence, 'analysis_id' => $sequence, 'analysis_status' => $status,
        'conclusions' => array_map(fn (string $code): array => [
            'code' => $code, 'level' => 'educational_finding',
            'title' => ['en' => "Title {$code}", 'ar' => "عنوان {$code}"],
            'summary' => ['en' => "Summary {$code}", 'ar' => "ملخص {$code}"],
            'rule_codes' => [],
        ], $codes),
        'missing_information' => [],
    ];
}

test('a code present only in the earliest succeeded analysis is DISAPPEARED', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', ['possible_anemia_pattern']),
        transitionTimelineEntry(2, 'SUCCEEDED', []),
    ];

    $result = (new ClassifyConclusionTransitions)->handle($timeline);

    expect($result)->toHaveCount(1)
        ->and($result[0]['conclusion_code'])->toBe('possible_anemia_pattern')
        ->and($result[0]['transition'])->toBe('DISAPPEARED')
        ->and($result[0]['present_in_latest'])->toBeFalse();
});

test('a code present only in the latest succeeded analysis is APPEARED', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', []),
        transitionTimelineEntry(2, 'SUCCEEDED', ['possible_leukocytosis_pattern']),
    ];

    $result = (new ClassifyConclusionTransitions)->handle($timeline);

    expect($result)->toHaveCount(1)
        ->and($result[0]['conclusion_code'])->toBe('possible_leukocytosis_pattern')
        ->and($result[0]['transition'])->toBe('APPEARED')
        ->and($result[0]['present_in_latest'])->toBeTrue();
});

test('a code present in both the earliest and latest succeeded analyses is PERSISTED', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', ['possible_anemia_pattern']),
        transitionTimelineEntry(2, 'SUCCEEDED', ['possible_anemia_pattern']),
    ];

    $result = (new ClassifyConclusionTransitions)->handle($timeline);

    expect($result)->toHaveCount(1)
        ->and($result[0]['transition'])->toBe('PERSISTED');
});

test('the exact task example: anemia PERSISTED, thrombocytosis DISAPPEARED, leukocytosis APPEARED - with no duplicate rows', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', ['possible_anemia_pattern', 'possible_thrombocytosis_pattern']),
        transitionTimelineEntry(2, 'SUCCEEDED', ['possible_anemia_pattern', 'possible_leukocytosis_pattern']),
    ];

    $result = collect((new ClassifyConclusionTransitions)->handle($timeline))->keyBy('conclusion_code');

    expect($result)->toHaveCount(3)
        ->and($result['possible_anemia_pattern']['transition'])->toBe('PERSISTED')
        ->and($result['possible_thrombocytosis_pattern']['transition'])->toBe('DISAPPEARED')
        ->and($result['possible_leukocytosis_pattern']['transition'])->toBe('APPEARED');
});

test('a code present only in a middle report (neither earliest nor latest) is TRANSIENT', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', []),
        transitionTimelineEntry(2, 'SUCCEEDED', ['possible_eosinophilia_pattern']),
        transitionTimelineEntry(3, 'SUCCEEDED', []),
    ];

    $result = (new ClassifyConclusionTransitions)->handle($timeline);

    expect($result)->toHaveCount(1)
        ->and($result[0]['transition'])->toBe('TRANSIENT')
        ->and($result[0]['occurrence_count'])->toBe(1)
        ->and($result[0]['first_seen_sequence'])->toBe(2)
        ->and($result[0]['last_seen_sequence'])->toBe(2);
});

test('a report with no succeeded analysis contributes nothing and is skipped', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', ['possible_anemia_pattern']),
        transitionTimelineEntry(2, 'FAILED', []),
        transitionTimelineEntry(3, 'SUCCEEDED', ['possible_anemia_pattern']),
    ];

    $result = (new ClassifyConclusionTransitions)->handle($timeline);

    expect($result)->toHaveCount(1)
        ->and($result[0]['transition'])->toBe('PERSISTED')
        ->and($result[0]['occurrence_count'])->toBe(2);
});

test('fewer than 2 succeeded analyses yields an empty list rather than a guessed transition', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', ['possible_anemia_pattern']),
        transitionTimelineEntry(2, 'FAILED', []),
    ];

    expect((new ClassifyConclusionTransitions)->handle($timeline))->toBe([]);
});

test('occurrence_count and present_in_latest are correct across 3+ reports', function () {
    $timeline = [
        transitionTimelineEntry(1, 'SUCCEEDED', ['possible_anemia_pattern']),
        transitionTimelineEntry(2, 'SUCCEEDED', ['possible_anemia_pattern']),
        transitionTimelineEntry(3, 'SUCCEEDED', ['possible_anemia_pattern']),
    ];

    $result = (new ClassifyConclusionTransitions)->handle($timeline);

    expect($result[0]['occurrence_count'])->toBe(3)
        ->and($result[0]['present_in_latest'])->toBeTrue()
        ->and($result[0]['transition'])->toBe('PERSISTED');
});
