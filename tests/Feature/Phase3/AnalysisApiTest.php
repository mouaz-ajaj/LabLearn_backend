<?php

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\PatientSex;
use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\ProcessReportAnalysis;
use App\Models\Analysis;
use App\Models\Report;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsRequestMapper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function phase3Metadata(): array
{
    return [
        'input_schema_version' => '1',
        'output_schema_version' => '1',
        'engine_version' => '1.0.0',
        'ruleset_version' => '2026.07.24.2',
        'analyte_catalog_version' => '2026.07.24.2',
        'knowledge_base_version' => '2026.07.24.2',
        'supported_categories' => ['CBC', 'DIABETES', 'LIVER_FUNCTION'],
    ];
}

function phase3ValidateClean(): array
{
    return [
        'success' => true,
        'blocking' => false,
        'ready_for_analysis' => true,
        'issues' => [],
    ];
}

function phase3AnalysisPayload(Analysis $analysis): array
{
    return [
        'success' => true,
        'schema_version' => '1',
        'input_schema_version' => '1',
        'output_schema_version' => '1',
        'engine_version' => '1.0.0',
        'ruleset_version' => $analysis->ruleset_version,
        'analyte_catalog_version' => '2026.07.24.2',
        'knowledge_base_version' => '2026.07.24.2',
        'request_id' => 'request-phase3-test',
        'category' => $analysis->report_category,
        'verified_result_set' => ['id' => $analysis->verified_result_set_id, 'version' => $analysis->verified_result_set_version],
        'status' => 'completed',
        'category_validation' => [
            'status' => 'MATCH', 'matched_analytes' => ['hemoglobin', 'wbc'],
            'other_category_analytes' => [], 'unsupported_analytes' => [],
            'missing_required_evidence' => ['platelets'], 'reason' => 'SUPPORTED_CATEGORY_EVIDENCE',
        ],
        'normalized_results' => [[
            'source_id' => 1, 'analyte_id' => 'hemoglobin', 'display_name' => 'HGB',
            'value' => 10.2, 'unit' => 'g/dL', 'original_value' => 10.2, 'original_unit' => 'g/dL',
            'reference_range' => ['low' => 12, 'high' => 16], 'status' => 'low',
        ]],
        'facts' => [['analyte_id' => 'hemoglobin', 'status' => 'low']],
        'conclusions' => [[
            'code' => 'possible_anemia_pattern', 'level' => 'educational_finding',
            'title' => ['en' => 'Possible anemia pattern', 'ar' => 'نمط محتمل لفقر الدم'],
            'summary' => ['en' => 'Low hemoglobin needs clinical context.', 'ar' => 'يحتاج انخفاض الهيموغلوبين إلى سياق سريري.'],
            'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'HGB', 'value' => 10.2, 'unit' => 'g/dL', 'status' => 'low']],
            'rule_codes' => ['cbc_low_hemoglobin'],
        ]],
        'rule_traces' => [[
            'rule_code' => 'cbc_low_hemoglobin', 'rule_version' => 1, 'fired' => true,
            'conditions' => [['group' => 'all', 'analyte_id' => 'hemoglobin', 'result' => true]],
            'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'HGB', 'value' => 10.2, 'unit' => 'g/dL', 'status' => 'low']],
            'conclusion_codes' => ['possible_anemia_pattern'],
        ]],
        'missing_information' => [[
            'code' => 'MISSING_REQUIRED_ANALYTE', 'analyte_id' => 'platelets',
            'message' => ['en' => 'Platelets are required.', 'ar' => 'الصفائح مطلوبة.'],
        ]],
        'warnings' => [],
        'summary' => ['en' => 'One educational finding.', 'ar' => 'ملاحظة تعليمية واحدة.'],
        'disclaimer' => ['en' => 'Educational only.', 'ar' => 'لأغراض تعليمية فقط.'],
    ];
}

function phase3Fixture(?User $user = null, string $gate = 'MATCH'): array
{
    $user ??= User::factory()->regular()->create();
    $report = Report::factory()->for($user)->create([
        'test_category' => ReportTestCategory::Cbc,
        'source_type' => ReportSourceType::Image,
        'status' => ReportStatus::Verified,
        'patient_age_years' => 24,
        'patient_sex' => PatientSex::Female,
    ]);
    $set = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 1, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 24, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase3-fixture-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => $gate, 'category_gate_category' => $gate === 'MATCH' ? ReportTestCategory::Cbc->value : null,
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $set->results()->createMany([
        ['label' => 'HGB', 'value' => '10.2', 'unit' => 'g/dL', 'reference_range' => '12-16', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1],
        ['label' => 'WBC', 'value' => '7.5', 'unit' => '10^9/L', 'reference_range' => '4-11', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 2],
    ]);

    return [$user, $report, $set->fresh('results')];
}

function phase3Token(User $user): string
{
    return $user->createToken('phase3-test')->plainTextToken;
}

test('owner queues an idempotent direct analysis for an exact verified version and ruleset', function () {
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3Metadata()),
        '*/v1/validate' => Http::response(phase3ValidateClean()),
    ]);
    [$user, $report, $set] = phase3Fixture();
    $uri = '/api/v1/reports/'.$report->getKey().'/analyze';
    $payload = ['verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::DirectResult->value];

    $first = $this->withToken(phase3Token($user))->postJson($uri, $payload)->assertAccepted();
    $second = $this->withToken(phase3Token($user))->postJson($uri, $payload)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Analysis::query()->count())->toBe(1)
        ->and($first->json('data.versions.ruleset'))->toBe('2026.07.24.2');
    Queue::assertPushed(ProcessReportAnalysis::class, 1);
});

test('student quiz first remains an explicit phase 3b handoff and never calls kbs', function () {
    Queue::fake();
    Http::preventStrayRequests();
    [$user, $report, $set] = phase3Fixture(User::factory()->student('4')->create());

    $this->withToken(phase3Token($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::QuizFirst->value,
    ])->assertConflict()->assertJsonPath('error_code', 'ANALYSIS_NOT_PROCESSABLE');

    expect(Analysis::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('category mismatch is blocked before kbs and preserves verified data', function () {
    Queue::fake();
    Http::preventStrayRequests();
    [$user, $report, $set] = phase3Fixture(gate: 'MISMATCH');

    $this->withToken(phase3Token($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::DirectResult->value,
    ])->assertConflict()->assertJsonPath('error_code', 'CATEGORY_GATE_NOT_MATCHED');

    expect($set->results()->count())->toBe(2)->and($report->refresh()->status)->toBe(ReportStatus::Verified);
    Http::assertNothingSent();
});

test('preflight blocks a queue dispatch when the kbs reports an ambiguous analyte', function () {
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3Metadata()),
        '*/v1/validate' => Http::response([
            'success' => true,
            'blocking' => true,
            'ready_for_analysis' => false,
            'issues' => [[
                'code' => 'AMBIGUOUS_ANALYTE',
                'label' => 'Lymphocytes',
                'candidates' => [
                    ['analyte_id' => 'lymphocytes_percent', 'display_name' => 'Lymphocytes Percentage'],
                    ['analyte_id' => 'alc', 'display_name' => 'Absolute Lymphocyte Count'],
                ],
                'message' => ['en' => "'Lymphocytes' could mean more than one analyte."],
            ]],
        ]),
    ]);
    [$user, $report, $set] = phase3Fixture();

    $response = $this->withToken(phase3Token($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::DirectResult->value,
    ]);

    $response->assertUnprocessable()->assertJsonPath('error_code', 'ANALYSIS_INPUT_INVALID')
        ->assertJsonPath('errors.issues.0.code', 'AMBIGUOUS_ANALYTE')
        ->assertJsonPath('errors.issues.0.label', 'Lymphocytes');
    expect(Analysis::query()->count())->toBe(0)
        ->and($set->results()->count())->toBe(2)
        ->and($report->refresh()->status)->toBe(ReportStatus::Verified);
    Queue::assertNotPushed(ProcessReportAnalysis::class);
});

test('preflight blocks on a missing required analyte before queue dispatch', function () {
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3Metadata()),
        '*/v1/validate' => Http::response([
            'success' => true,
            'blocking' => true,
            'ready_for_analysis' => false,
            'issues' => [[
                'code' => 'MISSING_REQUIRED_ANALYTE',
                'analyte_id' => 'wbc',
                'display_name' => 'White Blood Cells',
                'message' => ['en' => 'White Blood Cells is required for the selected panel.'],
            ]],
        ]),
    ]);
    [$user, $report, $set] = phase3Fixture();

    $this->withToken(phase3Token($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::DirectResult->value,
    ])->assertUnprocessable()->assertJsonPath('error_code', 'ANALYSIS_INPUT_INVALID')
        ->assertJsonPath('errors.issues.0.code', 'MISSING_REQUIRED_ANALYTE')
        ->assertJsonPath('errors.issues.0.analyte_id', 'wbc');
    expect(Analysis::query()->count())->toBe(0);
    Queue::assertNotPushed(ProcessReportAnalysis::class);
});

test('preflight allows analysis to proceed when only optional analytes are missing', function () {
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3Metadata()),
        '*/v1/validate' => Http::response(phase3ValidateClean()),
    ]);
    [$user, $report, $set] = phase3Fixture();

    $this->withToken(phase3Token($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::DirectResult->value,
    ])->assertAccepted();
    Queue::assertPushed(ProcessReportAnalysis::class, 1);
});

test('unavailable kbs validate endpoint returns a safe retryable boundary error', function () {
    config(['kbs.retry_attempts' => 1]);
    Http::fake([
        '*/v1/metadata' => Http::response(phase3Metadata()),
        '*/v1/validate' => Http::response(['error' => ['code' => 'unavailable']], 503),
    ]);
    [$user, $report, $set] = phase3Fixture();

    $this->withToken(phase3Token($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::DirectResult->value,
    ])->assertServiceUnavailable()->assertJsonPath('error_code', 'KBS_SERVICE_UNAVAILABLE');

    expect(Analysis::query()->count())->toBe(0);
});

test('unavailable kbs metadata returns a safe retryable boundary error', function () {
    config(['kbs.retry_attempts' => 1]);
    Http::fake(['*/v1/metadata' => Http::response(['error' => ['code' => 'unavailable']], 503)]);
    [$user, $report, $set] = phase3Fixture();

    $this->withToken(phase3Token($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => AnalysisFlow::DirectResult->value,
    ])->assertServiceUnavailable()->assertJsonPath('error_code', 'KBS_SERVICE_UNAVAILABLE');

    expect(Analysis::query()->count())->toBe(0);
});

test('queued job persists validated conclusions evidence and rule traces then completes the report lifecycle', function () {
    [$user, $report, $set] = phase3Fixture();
    $analysis = Analysis::query()->create([
        'report_id' => $report->getKey(), 'verified_result_set_id' => $set->getKey(), 'verified_result_set_version' => 1,
        'user_id' => $user->getKey(), 'report_category' => 'CBC', 'status' => AnalysisStatus::Queued,
        'flow' => AnalysisFlow::DirectResult, 'identity_key' => hash('sha256', fake()->uuid()),
        'schema_version' => 1, 'input_schema_version' => 1, 'engine_version' => '1.0.0',
        'ruleset_version' => '2026.07.24.2', 'catalog_version' => '2026.07.24.2',
    ]);
    Http::fake(['*/v1/analyze' => Http::response(phase3AnalysisPayload($analysis))]);

    (new ProcessReportAnalysis($analysis->getKey()))->handle(app(KbsClient::class), app(KbsRequestMapper::class));

    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->conclusions()->count())->toBe(1)
        ->and($analysis->ruleTraces()->count())->toBe(1)
        ->and($analysis->raw_kbs_response_json['conclusions'][0]['code'])->toBe('possible_anemia_pattern')
        // The job is the only write path for report completion; it must not wait on a later
        // read to flip the status, since GET must stay side-effect-free.
        ->and($report->refresh()->status)->toBe(ReportStatus::Completed);

    $this->withToken(phase3Token($user))->getJson('/api/v1/analyses/'.$analysis->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'SUCCEEDED')
        ->assertJsonPath('data.result.conclusions.0.rule_codes.0', 'cbc_low_hemoglobin')
        ->assertJsonPath('data.result.rule_traces.0.rule_version', '1')
        ->assertJsonPath('data.result.verified_results.0.label', 'HGB');
    // GET must be read-only: the report status is unchanged by fetching the result.
    expect($report->refresh()->status)->toBe(ReportStatus::Completed);
});

test('non retryable kbs rejection persists failed state without mutating verified rows', function () {
    config(['kbs.retry_attempts' => 1]);
    [$user, $report, $set] = phase3Fixture();
    $analysis = Analysis::query()->create([
        'report_id' => $report->getKey(), 'verified_result_set_id' => $set->getKey(), 'verified_result_set_version' => 1,
        'user_id' => $user->getKey(), 'report_category' => 'CBC', 'status' => AnalysisStatus::Queued,
        'flow' => AnalysisFlow::DirectResult, 'identity_key' => hash('sha256', fake()->uuid()),
        'ruleset_version' => '2026.07.24.2',
    ]);
    Http::fake(['*/v1/analyze' => Http::response(['error' => ['code' => 'INVALID_ANALYTE_VALUE']], 422)]);

    (new ProcessReportAnalysis($analysis->getKey()))->handle(app(KbsClient::class), app(KbsRequestMapper::class));

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe('INVALID_ANALYTE_VALUE')
        ->and($set->results()->count())->toBe(2)
        ->and($report->refresh()->status)->toBe(ReportStatus::Verified);
});

test('analysis ownership and request schema are enforced', function () {
    Queue::fake();
    Http::fake(['*/v1/metadata' => Http::response(phase3Metadata())]);
    [$owner, $report, $set] = phase3Fixture();
    $uri = '/api/v1/reports/'.$report->getKey().'/analyze';

    $this->postJson($uri, [])->assertUnauthorized();
    $this->withToken(phase3Token($owner))->postJson($uri, ['flow' => 'invented'])->assertUnprocessable();
});

test('another user cannot start or view an owners analysis', function () {
    Queue::fake();
    Http::preventStrayRequests();
    [$owner, $report, $set] = phase3Fixture();
    $other = User::factory()->create();

    $this->withToken(phase3Token($other))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => 'direct-result',
    ])->assertForbidden();
});
