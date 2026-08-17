<?php

use App\Enums\ReportTestCategory;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\ComparisonContextBuilder;
use App\Services\Ai\MedicalContext\ApprovedMedicalContextCatalog;
use App\Services\Ai\MedicalContext\ComparisonMedicalContextResolver;
use App\Services\Comparison\GroupAnalyteChanges;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * Verifies the Gemini payload follows data minimization: no patient/user identity,
 * no tokens, no raw files/OCR text, only structured, PRE-GROUPED trends/pattern
 * transitions - and that the requested language and role ARE present (Gemini needs
 * both, and the response schema is role-conditional).
 */
function phase4cSampleComparison(): array
{
    return [
        'category' => ReportTestCategory::Cbc->value,
        'generated_at' => now()->toISOString(),
        'reports' => [
            ['id' => 101, 'sequence' => 1, 'date' => '2026-01-10T00:00:00Z', 'status' => 'COMPLETED', 'verified_result_set_version' => 1, 'analysis_id' => 501, 'analysis_status' => 'SUCCEEDED'],
            ['id' => 102, 'sequence' => 2, 'date' => '2026-04-10T00:00:00Z', 'status' => 'COMPLETED', 'verified_result_set_version' => 1, 'analysis_id' => 502, 'analysis_status' => 'SUCCEEDED'],
        ],
        'analytes' => [
            [
                'analyte_id' => 'hemoglobin', 'display_name' => 'Hemoglobin', 'display_name_ar' => 'الهيموغلوبين', 'unit' => 'g/dL', 'comparable' => true,
                'points' => [
                    ['report_id' => 101, 'sequence' => 1, 'value' => 8.5, 'raw_value' => '8.5', 'unit' => 'g/dL', 'reference_status' => 'BELOW_REFERENCE', 'reference_low' => 12.0, 'reference_high' => 16.0, 'match_basis' => 'KBS_ANALYTE_ID'],
                    ['report_id' => 102, 'sequence' => 2, 'value' => 9.5, 'raw_value' => '9.5', 'unit' => 'g/dL', 'reference_status' => 'BELOW_REFERENCE', 'reference_low' => 12.0, 'reference_high' => 16.0, 'match_basis' => 'KBS_ANALYTE_ID'],
                ],
                'trend' => 'INCREASED', 'reference_trend' => 'MOVED_CLOSER_TO_REFERENCE',
                'lab_change_classification' => 'MOVED_CLOSER_BUT_STILL_ABNORMAL', 'earliest_status' => 'BELOW_REFERENCE', 'latest_status' => 'BELOW_REFERENCE',
            ],
            [
                'analyte_id' => 'platelets', 'display_name' => 'Platelets', 'display_name_ar' => 'الصفيحات', 'unit' => '10^9/L', 'comparable' => true,
                'points' => [
                    ['report_id' => 101, 'sequence' => 1, 'value' => 520, 'raw_value' => '520', 'unit' => '10^9/L', 'reference_status' => 'ABOVE_REFERENCE', 'reference_low' => 150.0, 'reference_high' => 450.0, 'match_basis' => 'KBS_ANALYTE_ID'],
                    ['report_id' => 102, 'sequence' => 2, 'value' => 350, 'raw_value' => '350', 'unit' => '10^9/L', 'reference_status' => 'WITHIN_REFERENCE', 'reference_low' => 150.0, 'reference_high' => 450.0, 'match_basis' => 'KBS_ANALYTE_ID'],
                ],
                'trend' => 'DECREASED', 'reference_trend' => 'MOVED_CLOSER_TO_REFERENCE',
                'lab_change_classification' => 'NORMALIZED', 'earliest_status' => 'ABOVE_REFERENCE', 'latest_status' => 'WITHIN_REFERENCE',
            ],
        ],
        'kbs_timeline' => [
            ['report_id' => 101, 'sequence' => 1, 'analysis_id' => 501, 'analysis_status' => 'SUCCEEDED', 'conclusions' => [
                ['code' => 'possible_anemia_pattern', 'level' => 'educational_finding', 'title' => ['en' => 'Possible anemia pattern', 'ar' => 'نمط محتمل لفقر الدم'], 'summary' => ['en' => 'Low hemoglobin needs clinical context.', 'ar' => 'يحتاج انخفاض الهيموغلوبين إلى سياق سريري.'], 'rule_codes' => ['R001']],
            ], 'missing_information' => []],
            ['report_id' => 102, 'sequence' => 2, 'analysis_id' => 502, 'analysis_status' => 'SUCCEEDED', 'conclusions' => [
                ['code' => 'possible_anemia_pattern', 'level' => 'educational_finding', 'title' => ['en' => 'Possible anemia pattern', 'ar' => 'نمط محتمل لفقر الدم'], 'summary' => ['en' => 'Low hemoglobin needs clinical context.', 'ar' => 'يحتاج انخفاض الهيموغلوبين إلى سياق سريري.'], 'rule_codes' => ['R001']],
            ], 'missing_information' => []],
        ],
        'pattern_transitions' => [
            [
                'conclusion_code' => 'possible_anemia_pattern', 'transition' => 'PERSISTED', 'level' => 'educational_finding',
                'title' => ['en' => 'Possible anemia pattern', 'ar' => 'نمط محتمل لفقر الدم'],
                'summary' => ['en' => 'Low hemoglobin needs clinical context.', 'ar' => 'يحتاج انخفاض الهيموغلوبين إلى سياق سريري.'],
                'rule_codes' => ['R001'], 'first_seen_sequence' => 1, 'last_seen_sequence' => 2, 'present_in_latest' => true, 'occurrence_count' => 2,
            ],
        ],
    ];
}

function phase4cContextBuilder(): ComparisonContextBuilder
{
    $catalog = new ApprovedMedicalContextCatalog(base_path('resources/medical_context'));

    return new ComparisonContextBuilder(new GroupAnalyteChanges, new ComparisonMedicalContextResolver($catalog));
}

test('the Gemini payload contains no patient or account identity, tokens, or raw files', function () {
    $user = User::factory()->create(['name' => 'Very Secret Name', 'email' => 'secret.user@example.test', 'role' => UserRole::Regular]);
    $comparison = phase4cSampleComparison();

    $payload = phase4cContextBuilder()->build($comparison, $user, 'en');
    $encoded = json_encode($payload);

    expect($encoded)->not->toContain('Very Secret Name')
        ->and($encoded)->not->toContain('secret.user@example.test')
        ->and($payload)->not->toHaveKey('token')
        ->and($payload)->not->toHaveKey('access_token')
        ->and($payload)->not->toHaveKey('email')
        ->and($payload)->not->toHaveKey('name')
        ->and($payload)->not->toHaveKey('file')
        ->and($payload)->not->toHaveKey('file_path')
        ->and($payload)->not->toHaveKey('filename')
        ->and($encoded)->not->toContain('.pdf')
        ->and($encoded)->not->toContain('raw_kbs_response');
});

test('the Gemini payload includes the requested language and the users role', function () {
    $user = User::factory()->create(['role' => UserRole::Student]);
    $comparison = phase4cSampleComparison();

    $payload = phase4cContextBuilder()->build($comparison, $user, 'ar');

    expect($payload['language'])->toBe('ar')
        ->and($payload['role'])->toBe('student')
        ->and($payload['task'])->toBe('comparison_contextualization');
});

test('a Regular account resolves to the regular role', function () {
    $user = User::factory()->create(['role' => UserRole::Regular]);
    $comparison = phase4cSampleComparison();

    $payload = phase4cContextBuilder()->build($comparison, $user, 'en');

    expect($payload['role'])->toBe('regular');
});

test('Laravel pre-groups analytes into sections instead of sending a flat list', function () {
    $comparison = phase4cSampleComparison();
    $user = User::factory()->create(['role' => UserRole::Regular]);

    $payload = phase4cContextBuilder()->build($comparison, $user, 'en');

    expect(collect($payload['better_but_still_abnormal'])->pluck('analyte_id')->all())->toBe(['hemoglobin'])
        ->and(collect($payload['normalized_findings'])->pluck('analyte_id')->all())->toBe(['platelets'])
        ->and($payload['new_or_worse_findings'])->toBe([])
        ->and($payload['persistent_abnormalities'])->toBe([]);
});

test('normalized and better-but-still-abnormal sections never contain the same analyte', function () {
    $comparison = phase4cSampleComparison();
    $user = User::factory()->create(['role' => UserRole::Regular]);

    $payload = phase4cContextBuilder()->build($comparison, $user, 'en');

    $normalizedIds = collect($payload['normalized_findings'])->pluck('analyte_id')->all();
    $betterIds = collect($payload['better_but_still_abnormal'])->pluck('analyte_id')->all();

    expect(array_intersect($normalizedIds, $betterIds))->toBe([]);
});

test('pattern_transitions are localized to the requested language', function () {
    $comparison = phase4cSampleComparison();
    $user = User::factory()->create(['role' => UserRole::Regular]);

    $payload = phase4cContextBuilder()->build($comparison, $user, 'ar');

    expect($payload['pattern_transitions'][0]['conclusion_code'])->toBe('possible_anemia_pattern')
        ->and($payload['pattern_transitions'][0]['transition'])->toBe('PERSISTED')
        ->and($payload['pattern_transitions'][0]['title'])->toBe('نمط محتمل لفقر الدم');
});

test('allowed_medical_context reuses the real Phase 4E catalog, scoped to APPEARED/PERSISTED conclusion codes', function () {
    $comparison = phase4cSampleComparison();
    $user = User::factory()->create(['role' => UserRole::Regular]);

    $payload = phase4cContextBuilder()->build($comparison, $user, 'en');

    expect(collect($payload['allowed_medical_context']['groups'])->pluck('context_group_code')->all())->toContain('GENERAL_ANEMIA_CONTEXT');
});

test('a DISAPPEARED pattern is excluded from allowed_medical_context', function () {
    $comparison = phase4cSampleComparison();
    $comparison['pattern_transitions'][0]['transition'] = 'DISAPPEARED';
    $user = User::factory()->create(['role' => UserRole::Regular]);

    $payload = phase4cContextBuilder()->build($comparison, $user, 'en');

    expect($payload['allowed_medical_context']['groups'])->toBe([]);
});

test('allowedAnalyteIdsBySection/allowedPatternTransitions/allowedMedicalContextCodes expose exactly what was supplied, for the response validator allow-list', function () {
    $comparison = phase4cSampleComparison();
    $builder = phase4cContextBuilder();

    $bySection = $builder->allowedAnalyteIdsBySection($comparison);
    $transitions = $builder->allowedPatternTransitions($comparison);
    $medicalContext = $builder->allowedMedicalContextCodes($comparison);

    expect($bySection['better_but_still_abnormal'])->toBe(['hemoglobin'])
        ->and($bySection['normalized'])->toBe(['platelets'])
        ->and($transitions)->toBe(['possible_anemia_pattern' => 'PERSISTED'])
        ->and($medicalContext['differential'])->toContain('ANEMIA_DDX_MECHANISM_UNDERPRODUCTION');
});
