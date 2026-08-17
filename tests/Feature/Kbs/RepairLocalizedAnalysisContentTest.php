<?php

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

/**
 * Builds a SUCCEEDED analysis shaped exactly like the confirmed real historical
 * bug: title_json.ar is null and summary_json.ar holds English prose under the
 * Arabic key (the report_builder.py why_ar fallback bug), using the REAL KBS
 * conclusion_code/rule_code ("possible_anemia_pattern"/"R001") so the repair
 * command's real, un-mocked KbsLocalizationCatalog lookup (reading the actual
 * kbs/knowledge_base/*.json files) succeeds exactly as it would in production.
 */
function repairFixture(): Analysis
{
    $user = User::factory()->regular()->create();
    $report = Report::factory()->for($user)->create([
        'test_category' => ReportTestCategory::Cbc,
        'source_type' => ReportSourceType::Image,
        'status' => ReportStatus::Completed,
    ]);
    $set = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 1, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 30, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'repair-fixture-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => 'CBC',
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);

    $analysis = Analysis::factory()->create([
        'report_id' => $report->getKey(),
        'verified_result_set_id' => $set->getKey(),
        'verified_result_set_version' => 1,
        'user_id' => $user->getKey(),
        'report_category' => 'CBC',
        'status' => AnalysisStatus::Succeeded,
        'ruleset_version' => 'test-ruleset',
        'started_at' => now()->subDay(),
        'completed_at' => now()->subDay(),
        'normalized_results_json' => [[
            'source_id' => 1, 'analyte_id' => 'hemoglobin', 'display_name' => 'Hemoglobin',
            'value' => 9.5, 'unit' => 'g/dL', 'original_value' => 9.5, 'original_unit' => 'g/dL',
            'reference_range' => ['low' => 12, 'high' => 16], 'status' => 'low',
        ]],
    ]);

    AnalysisConclusion::factory()->for($analysis)->create([
        'conclusion_code' => 'possible_anemia_pattern',
        'level' => 'educational_finding',
        'title_json' => ['en' => 'Possible anemia pattern', 'ar' => null],
        'summary_json' => [
            'en' => 'Low hemoglobin, hematocrit, or RBC may suggest an anemia pattern.',
            // The confirmed real bug shape: English prose under the ar key.
            'ar' => 'One or more red blood cell measurements are low, which may suggest an anemia pattern.',
        ],
        'evidence_json' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']],
        'rule_codes_json' => ['R001'],
        'display_order' => 1,
    ]);

    RuleTrace::query()->create([
        'analysis_id' => $analysis->getKey(),
        'rule_code' => 'R001',
        'rule_version' => 1,
        'fired' => true,
        'conditions_json' => [],
        'evidence_json' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']],
        'conclusion_codes_json' => ['possible_anemia_pattern'],
    ]);

    return $analysis->fresh(['conclusions', 'ruleTraces']);
}

test('dry run reports planned repairs without writing anything', function () {
    $analysis = repairFixture();
    $conclusion = $analysis->conclusions->first();

    $this->artisan('kbs:repair-localized-analysis-content')
        ->assertSuccessful();

    $conclusion->refresh();
    expect($conclusion->title_json['ar'])->toBeNull()
        ->and($conclusion->summary_json['ar'])->toBe('One or more red blood cell measurements are low, which may suggest an anemia pattern.');
});

test('--apply repairs the mislabeled title and summary using the real KBS catalog, without touching medical fields', function () {
    $analysis = repairFixture();
    $conclusion = $analysis->conclusions->first();
    $originalRuleCodes = $conclusion->rule_codes_json;
    $originalStatus = $analysis->status;
    $originalVerifiedResultSetId = $analysis->verified_result_set_id;
    $originalStartedAt = $analysis->started_at;
    $originalNormalizedValue = $analysis->normalized_results_json[0]['value'];
    $originalNormalizedStatus = $analysis->normalized_results_json[0]['status'];

    $this->artisan('kbs:repair-localized-analysis-content', ['--apply' => true])
        ->assertSuccessful();

    $conclusion->refresh();
    $analysis->refresh();

    // Title was null -> now genuine Arabic, sourced from conditions.json's
    // real name_ar for possible_anemia_pattern (added by the localization repair).
    expect($conclusion->title_json['ar'])->toBe('نمط فقر الدم المحتمل')
        ->and($conclusion->title_json['en'])->toBe('Possible anemia pattern');

    // Summary was English-under-ar -> now genuine Arabic, sourced from rules.json's
    // real R001 explanation_ar, preserving the same probabilistic hedging.
    expect($conclusion->summary_json['ar'])->toBe('انخفاض واحد أو أكثر من قياسات الكريات الحمراء قد يشير إلى نمط فقر الدم.')
        ->and($conclusion->summary_json['en'])->toBe('Low hemoglobin, hematocrit, or RBC may suggest an anemia pattern.');

    // Evidence label gained an Arabic sibling; the English label, value, unit,
    // status, and analyte_id are untouched.
    $evidence = $conclusion->evidence_json[0];
    expect($evidence['label_ar'])->toBe('الهيموغلوبين')
        ->and($evidence['label'])->toBe('Hemoglobin')
        ->and($evidence['value'])->toBe(9.5)
        ->and($evidence['unit'])->toBe('g/dL')
        ->and($evidence['status'])->toBe('low')
        ->and($evidence['analyte_id'])->toBe('hemoglobin');

    // Nothing medical/identity-related changed.
    expect($conclusion->rule_codes_json)->toBe($originalRuleCodes)
        ->and($conclusion->conclusion_code)->toBe('possible_anemia_pattern')
        ->and($analysis->status)->toBe($originalStatus)
        ->and($analysis->verified_result_set_id)->toBe($originalVerifiedResultSetId)
        ->and($analysis->started_at->eq($originalStartedAt))->toBeTrue()
        ->and($analysis->normalized_results_json[0]['value'])->toBe($originalNormalizedValue)
        ->and($analysis->normalized_results_json[0]['status'])->toBe($originalNormalizedStatus)
        ->and($analysis->normalized_results_json[0]['display_name_ar'])->toBe('الهيموغلوبين');

    $trace = RuleTrace::query()->where('analysis_id', $analysis->getKey())->first();
    expect($trace->evidence_json[0]['label_ar'])->toBe('الهيموغلوبين')
        ->and($trace->rule_code)->toBe('R001')
        ->and($trace->conclusion_codes_json)->toBe(['possible_anemia_pattern']);
});

test('already-correct bilingual data is left byte-for-byte unchanged', function () {
    $analysis = repairFixture();
    $conclusion = $analysis->conclusions->first();
    $conclusion->update([
        'title_json' => ['en' => 'Possible anemia pattern', 'ar' => 'نمط فقر الدم المحتمل'],
        'summary_json' => ['en' => 'Low hemoglobin may suggest an anemia pattern.', 'ar' => 'قد يشير انخفاض الهيموغلوبين إلى نمط فقر الدم.'],
    ]);
    // Re-read from the database (rather than comparing to the in-memory,
    // pre-JSON-round-trip array) so this only detects a change the repair
    // command itself made - not incidental JSON key-order differences MySQL's
    // JSON column type introduces between write and read.
    $conclusion->refresh();
    $beforeTitle = $conclusion->title_json;
    $beforeSummary = $conclusion->summary_json;

    $this->artisan('kbs:repair-localized-analysis-content', ['--apply' => true])->assertSuccessful();

    $conclusion->refresh();
    expect($conclusion->title_json)->toBe($beforeTitle)
        ->and($conclusion->summary_json)->toBe($beforeSummary);
});

test('the repair is idempotent - a second --apply run repairs nothing further', function () {
    repairFixture();

    $this->artisan('kbs:repair-localized-analysis-content', ['--apply' => true])->assertSuccessful();
    $firstUpdatedAt = AnalysisConclusion::query()->first()->updated_at;

    // Second run should find nothing left to repair.
    $this->artisan('kbs:repair-localized-analysis-content', ['--apply' => true])->assertSuccessful();
    $secondUpdatedAt = AnalysisConclusion::query()->first()->fresh()->updated_at;

    expect($secondUpdatedAt->eq($firstUpdatedAt))->toBeTrue();
});

test('a FAILED analysis is never scanned or modified', function () {
    $analysis = repairFixture();
    $analysis->update(['status' => AnalysisStatus::Failed]);
    $conclusion = $analysis->conclusions->first();
    $before = $conclusion->title_json;

    $this->artisan('kbs:repair-localized-analysis-content', ['--apply' => true])->assertSuccessful();

    $conclusion->refresh();
    expect($conclusion->title_json)->toBe($before);
});
