<?php

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\PatientSex;
use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Models\Analysis;
use App\Models\AnalysisConclusion;
use App\Models\Report;
use App\Models\RuleTrace;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Ai\ResultExplanationContextBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

// tests/Pest.php only auto-applies TestCase+LazilyRefreshDatabase to Feature/, not
// Unit/ - matching the established Phase 4C precedent for its own Unit test.
uses(TestCase::class, LazilyRefreshDatabase::class);

function phase4eContextFixture(): array
{
    $user = User::factory()->create(['name' => 'Jane Q. Patient', 'email' => 'jane.patient@example.test']);
    $report = Report::factory()->for($user)->create([
        'test_category' => ReportTestCategory::Cbc,
        'source_type' => ReportSourceType::Image,
        'status' => ReportStatus::Completed,
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female,
    ]);
    $set = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 1, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 30, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase4e-context-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => ReportTestCategory::Cbc->value,
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $set->results()->create(['label' => 'HGB', 'value' => '9.5', 'unit' => 'g/dL', 'reference_range' => '12-16', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1]);

    $analysis = Analysis::factory()->create([
        'report_id' => $report->getKey(),
        'verified_result_set_id' => $set->getKey(),
        'verified_result_set_version' => 1,
        'user_id' => $user->getKey(),
        'report_category' => ReportTestCategory::Cbc->value,
        'status' => AnalysisStatus::Succeeded,
        'flow' => AnalysisFlow::DirectResult,
        'identity_key' => hash('sha256', 'phase4e-context-'.fake()->uuid()),
        'ruleset_version' => 'test-ruleset',
        'summary_json' => ['en' => 'One finding.', 'ar' => 'ملاحظة واحدة.'],
        'normalized_results_json' => [[
            'source_id' => 1, 'analyte_id' => 'hemoglobin', 'display_name' => 'Hemoglobin',
            'value' => 9.5, 'unit' => 'g/dL', 'original_value' => 9.5, 'original_unit' => 'g/dL',
            'reference_range' => ['low' => 12, 'high' => 16], 'status' => 'low',
        ]],
        'missing_information_json' => [],
    ]);

    AnalysisConclusion::factory()->for($analysis)->create([
        'conclusion_code' => 'possible_anemia_pattern',
        'level' => 'educational_finding',
        'title_json' => ['en' => 'Possible anemia pattern'],
        'summary_json' => ['en' => 'Low hemoglobin needs clinical context.'],
        'evidence_json' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']],
        'rule_codes_json' => ['R001'],
        'display_order' => 1,
    ]);

    RuleTrace::query()->create([
        'analysis_id' => $analysis->getKey(), 'rule_code' => 'R001', 'rule_version' => 1, 'fired' => true,
        'conditions_json' => [], 'evidence_json' => [], 'conclusion_codes_json' => ['possible_anemia_pattern'],
    ]);

    return [$user, $analysis->fresh(['conclusions', 'ruleTraces'])];
}

test('the built payload never includes the users name, email, or any identity field', function () {
    [, $analysis] = phase4eContextFixture();
    $context = app(ResultExplanationContextBuilder::class)->build($analysis, 'regular', 'en');
    $serialized = json_encode($context);

    expect($serialized)->not->toContain('Jane')
        ->and($serialized)->not->toContain('Patient')
        ->and($serialized)->not->toContain('jane.patient@example.test')
        ->and($serialized)->not->toContain('example.test');
});

test('the built payload never includes a token, filename, storage path, or idempotency key', function () {
    [, $analysis] = phase4eContextFixture();
    $context = app(ResultExplanationContextBuilder::class)->build($analysis, 'regular', 'en');
    $serialized = json_encode($context);

    // 'path' alone is deliberately not checked here: the approved medical context
    // catalog (2026-08-17 content redesign) legitimately contains the word
    // "pathophysiology" in its student_context - 'storage_path'/'storage' below
    // is the precise, reliable signal for an actual leaked internal file path.
    foreach (['token', 'idempotency', 'storage', '.pdf', '.jpg', '.png', 'filename'] as $forbidden) {
        expect(str_contains(strtolower($serialized), $forbidden))->toBeFalse("payload must not contain '{$forbidden}'");
    }
});

test('the built payload includes language, role, and category, and no report/analysis database ids', function () {
    [, $analysis] = phase4eContextFixture();
    $context = app(ResultExplanationContextBuilder::class)->build($analysis, 'student', 'ar');

    expect($context['language'])->toBe('ar')
        ->and($context['user_role'])->toBe('student')
        ->and($context['category'])->toBe('CBC')
        ->and($context)->not->toHaveKey('analysis_id')
        ->and($context)->not->toHaveKey('report_id')
        ->and($context)->not->toHaveKey('user_id');
});

test('allowed_medical_context resolves the reviewed catalog group for a covered conclusion code', function () {
    // 2026-08-17 content redesign: possible_anemia_pattern has approved coverage
    // (GENERAL_ANEMIA_CONTEXT) in resources/medical_context/cbc.json.
    [, $analysis] = phase4eContextFixture();
    $context = app(ResultExplanationContextBuilder::class)->build($analysis, 'regular', 'en');
    $groups = $context['allowed_medical_context']['groups'];

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['context_group_code'])->toBe('GENERAL_ANEMIA_CONTEXT')
        ->and($groups[0]['related_conclusion_codes'])->toBe(['possible_anemia_pattern'])
        ->and($groups[0]['possible_causes'])->not->toBeEmpty()
        ->and($groups[0]['possible_symptoms'])->not->toBeEmpty()
        // Regular and Student receive the identical resolved context - only the
        // prompt/response schema differentiate what each role's output contains.
        ->and($groups[0]['student_context'])->not->toBeNull();
});

test('allowed_medical_context is honestly empty for a conclusion code with no approved catalog coverage', function () {
    [, $analysis] = phase4eContextFixture();
    $analysis->conclusions->first()->update(['conclusion_code' => 'relative_absolute_differential_discordance']);
    $context = app(ResultExplanationContextBuilder::class)->build($analysis->fresh(['conclusions']), 'regular', 'en');

    expect($context['allowed_medical_context']['groups'])->toBe([]);
});

test('allowedMedicalContextCodes returns exactly the codes resolved for this analysis, per field type', function () {
    [, $analysis] = phase4eContextFixture();
    $builder = app(ResultExplanationContextBuilder::class);

    $codes = $builder->allowedMedicalContextCodes($analysis);

    expect($codes)->toHaveKeys(['causes', 'symptoms', 'next_steps', 'red_flags', 'differential', 'distinguishing'])
        ->and($codes['causes'])->not->toBeEmpty()
        ->and($codes['symptoms'])->not->toBeEmpty();
});

test('conclusions and analytes in the payload only ever contain what this analysis actually has', function () {
    [, $analysis] = phase4eContextFixture();
    $context = app(ResultExplanationContextBuilder::class)->build($analysis, 'regular', 'en');

    expect($context['analysis']['conclusions'])->toHaveCount(1)
        ->and($context['analysis']['conclusions'][0]['code'])->toBe('possible_anemia_pattern')
        ->and($context['analysis']['fired_rule_codes'])->toBe(['R001'])
        ->and($context['verified_or_normalized_analytes'])->toHaveCount(1)
        ->and($context['verified_or_normalized_analytes'][0]['analyte_id'])->toBe('hemoglobin');
});

test('allowedConclusionCodes allowedAnalyteIds and allowedRuleCodes match exactly what the analysis contains', function () {
    [, $analysis] = phase4eContextFixture();
    $builder = app(ResultExplanationContextBuilder::class);

    expect($builder->allowedConclusionCodes($analysis))->toBe(['possible_anemia_pattern'])
        ->and($builder->allowedAnalyteIds($analysis))->toBe(['hemoglobin'])
        ->and($builder->allowedRuleCodes($analysis))->toBe(['R001']);
});

test('the free text verified result reference range is never sent - only KBS structured normalized results', function () {
    [, $analysis] = phase4eContextFixture();
    $context = app(ResultExplanationContextBuilder::class)->build($analysis, 'regular', 'en');

    // "12-16" is the free-text VerifiedResult.reference_range string; the structured
    // KBS reference_range (low/high numbers inside normalized_results_json) is what
    // must appear instead.
    expect(json_encode($context))->not->toContain('"12-16"')
        ->and($context['verified_or_normalized_analytes'][0]['reference_range'])->toBe(['low' => 12, 'high' => 16]);
});
