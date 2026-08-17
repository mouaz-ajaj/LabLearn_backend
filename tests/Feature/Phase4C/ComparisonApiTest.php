<?php

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\PatientSex;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\ProcessReportAnalysis;
use App\Jobs\ProcessReportOcr;
use App\Models\Analysis;
use App\Models\AnalysisConclusion;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\RuleTrace;
use App\Models\User;
use App\Models\VerifiedResult;
use App\Models\VerifiedResultSet;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsRequestMapper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Global fixture helpers reused across tests/Feature/Phase4C/*.php (Pest convention
// already established in this suite - top-level functions in one test file are
// available to every file in the same run).

function phase4cToken(User $user): string
{
    return $user->createToken('phase4c-test')->plainTextToken;
}

/**
 * @param  array<int, array{label:string,value:string,unit?:?string,reference_range?:?string,canonical_analyte_id_hint?:?string}>  $rows
 * @return array{0: Report, 1: VerifiedResultSet}
 */
function phase4cVerifiedReport(User $user, ReportTestCategory $category, array $rows, ?DateTimeInterface $createdAt = null): array
{
    $timestamp = $createdAt ?? now();
    $report = Report::factory()->for($user)->create([
        'test_category' => $category,
        'status' => ReportStatus::Verified,
        'patient_age_years' => 29,
        'patient_sex' => PatientSex::Female,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $set = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 1, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 29, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase4c-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => $category->value,
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => $timestamp,
    ]);
    $order = 1;
    foreach ($rows as $row) {
        $set->results()->create([
            'label' => $row['label'], 'value' => $row['value'], 'unit' => $row['unit'] ?? null,
            'reference_range' => $row['reference_range'] ?? null,
            'canonical_analyte_id_hint' => $row['canonical_analyte_id_hint'] ?? null,
            'was_added_manually' => true, 'was_modified' => false, 'display_order' => $order++,
        ]);
    }

    return [$report, $set->fresh('results')];
}

/**
 * Registers ONE dynamic KBS fake for the whole test (Http::fake() stubs are checked
 * in REGISTRATION order - a second Http::fake() call for the same URL pattern does
 * NOT override the first; the first-registered stub keeps winning). This closure
 * instead looks up the correct canned payload per request from a per-test registry
 * keyed by verified_result_set id, so calling phase4cRunAnalysis() more than once in
 * a single test (e.g. to build two reports' analyses) returns the right response for
 * each call rather than silently replaying the first one for every later request.
 */
function phase4cRegisterKbsFake(): void
{
    if ($GLOBALS['phase4cKbsFakeRegistered'] ?? false) {
        return;
    }
    $GLOBALS['phase4cKbsFakeRegistered'] = true;
    Http::fake([
        '*/v1/analyze' => function ($request) {
            $setId = (int) data_get($request->data(), 'verified_result_set.id');
            $payload = $GLOBALS['phase4cKbsPayloads'][$setId] ?? null;

            return $payload !== null ? Http::response($payload) : Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
        },
    ]);
}

/**
 * Runs the real ProcessReportAnalysis job against a fake KBS response, producing a
 * genuinely persisted, succeeded Analysis with real AnalysisConclusion/RuleTrace rows
 * - the same technique already established in Phase 3/3B/4B tests.
 *
 * @param  array<int, array<string, mixed>>  $normalizedResults
 * @param  array<int, array<string, mixed>>  $conclusions
 */
function phase4cRunAnalysis(Report $report, VerifiedResultSet $set, User $user, array $normalizedResults, array $conclusions = [], string $category = 'CBC'): Analysis
{
    $analysis = Analysis::query()->create([
        'report_id' => $report->getKey(), 'verified_result_set_id' => $set->getKey(), 'verified_result_set_version' => $set->version,
        'user_id' => $user->getKey(), 'report_category' => $category, 'status' => AnalysisStatus::Queued,
        'flow' => AnalysisFlow::DirectResult, 'identity_key' => hash('sha256', fake()->uuid()),
        'schema_version' => 1, 'input_schema_version' => 1, 'engine_version' => '1.0.0',
        'ruleset_version' => 'phase4c-ruleset', 'catalog_version' => 'phase4c-catalog',
    ]);
    $GLOBALS['phase4cKbsPayloads'][$set->getKey()] = [
        'success' => true, 'schema_version' => '1', 'input_schema_version' => '1', 'output_schema_version' => '1',
        'engine_version' => '1.0.0', 'ruleset_version' => $analysis->ruleset_version, 'analyte_catalog_version' => 'phase4c-catalog',
        'knowledge_base_version' => 'phase4c-catalog', 'request_id' => 'phase4c-test',
        'category' => $category,
        'verified_result_set' => ['id' => $set->getKey(), 'version' => $set->version],
        'status' => 'completed',
        'category_validation' => ['status' => 'MATCH', 'matched_analytes' => [], 'other_category_analytes' => [], 'unsupported_analytes' => [], 'missing_required_evidence' => [], 'reason' => 'SUPPORTED_CATEGORY_EVIDENCE'],
        'normalized_results' => $normalizedResults,
        'facts' => [],
        'conclusions' => $conclusions,
        'rule_traces' => [],
        'missing_information' => [],
        'warnings' => [],
        'summary' => ['en' => 'Educational summary.', 'ar' => 'ملخص تعليمي.'],
        'disclaimer' => ['en' => 'Educational only.', 'ar' => 'لأغراض تعليمية فقط.'],
    ];
    phase4cRegisterKbsFake();
    (new ProcessReportAnalysis($analysis->getKey()))->handle(app(KbsClient::class), app(KbsRequestMapper::class));

    return $analysis->refresh();
}

function phase4cNormalizedRow(int $sourceId, string $analyteId, string $displayName, float $value, string $unit, float $low, float $high, string $status = 'normal'): array
{
    return [
        'source_id' => $sourceId, 'analyte_id' => $analyteId, 'display_name' => $displayName,
        'value' => $value, 'unit' => $unit, 'original_value' => $value, 'original_unit' => $unit,
        'reference_range' => ['low' => $low, 'high' => $high], 'status' => $status,
    ];
}

beforeEach(function () {
    Queue::fake();
    $GLOBALS['phase4cKbsFakeRegistered'] = false;
    $GLOBALS['phase4cKbsPayloads'] = [];
});

test('comparison requires authentication', function () {
    $this->postJson('/api/v1/comparisons', ['report_ids' => [1, 2], 'language' => 'en'])
        ->assertUnauthorized()->assertJsonPath('error_code', 'UNAUTHENTICATED');
});

test('comparison requires at least two report ids', function () {
    $user = User::factory()->create();
    [$reportA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', ['report_ids' => [$reportA->getKey()], 'language' => 'en'])
        ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR');
});

test('comparison rejects duplicate report ids', function () {
    $user = User::factory()->create();
    [$reportA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportA->getKey()], 'language' => 'en',
    ])->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR');
});

test('comparison validates the language field strictly', function () {
    $user = User::factory()->create();
    [$reportA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']]);
    [$reportB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '10.0']]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'fr',
    ])->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR');
});

test('comparison rejects reports of different categories before any AI request', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    [$cbc] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']]);
    [$diabetes] = phase4cVerifiedReport($user, ReportTestCategory::Diabetes, [['label' => 'HbA1c', 'value' => '6.1']]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$cbc->getKey(), $diabetes->getKey()], 'language' => 'en',
    ])->assertStatus(409)->assertJsonPath('error_code', 'COMPARISON_CATEGORY_MISMATCH');
    Http::assertNothingSent();
});

test('another users report is rejected with the same ownership convention used elsewhere, leaking no data', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    [$ownerReport] = phase4cVerifiedReport($owner, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']]);
    [$otherReport] = phase4cVerifiedReport($other, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '10.0']]);

    $response = $this->withToken(phase4cToken($owner))->postJson('/api/v1/comparisons', [
        'report_ids' => [$ownerReport->getKey(), $otherReport->getKey()], 'language' => 'en',
    ]);

    $response->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
    expect($response->json('data'))->toBeNull();
});

test('a report with no verified result set cannot be compared', function () {
    $user = User::factory()->create();
    [$verified] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']]);
    $unverified = Report::factory()->for($user)->create(['test_category' => ReportTestCategory::Cbc, 'status' => ReportStatus::Uploaded]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$verified->getKey(), $unverified->getKey()], 'language' => 'en',
    ])->assertStatus(409)->assertJsonPath('error_code', 'COMPARISON_REPORT_NOT_VERIFIED');
});

test('reports are returned in chronological order regardless of request order', function () {
    $user = User::factory()->create();
    [$older] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']], now()->subDays(10));
    [$newer] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '10.5']], now()->subDays(1));

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$newer->getKey(), $older->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.comparison.reports.0.id'))->toBe($older->getKey())
        ->and($response->json('data.comparison.reports.0.sequence'))->toBe(1)
        ->and($response->json('data.comparison.reports.1.id'))->toBe($newer->getKey())
        ->and($response->json('data.comparison.reports.1.sequence'))->toBe(2);
});

test('a value that rose across reports is classified as increased and moved closer to reference', function () {
    $user = User::factory()->create();
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2', 'unit' => 'g/dL', 'reference_range' => '12-16']], now()->subDays(20));
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '12.1', 'unit' => 'g/dL', 'reference_range' => '12-16']], now()->subDays(1));
    phase4cRunAnalysis($reportA, $setA, $user, [phase4cNormalizedRow($setA->results->first()->id, 'hemoglobin', 'Hemoglobin', 9.2, 'g/dL', 12, 16, 'low')]);
    phase4cRunAnalysis($reportB, $setB, $user, [phase4cNormalizedRow($setB->results->first()->id, 'hemoglobin', 'Hemoglobin', 12.1, 'g/dL', 12, 16, 'normal')]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'hemoglobin');
    expect($analyte['trend'])->toBe('INCREASED')
        ->and($analyte['reference_trend'])->toBe('MOVED_CLOSER_TO_REFERENCE')
        ->and($analyte['points'][0]['reference_status'])->toBe('BELOW_REFERENCE')
        ->and($analyte['points'][1]['reference_status'])->toBe('WITHIN_REFERENCE')
        ->and($analyte['comparable'])->toBeTrue();
});

test('a value that fell across reports is classified as decreased', function () {
    $user = User::factory()->create();
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'WBC', 'value' => '14.0', 'unit' => '10^9/L']], now()->subDays(20));
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'WBC', 'value' => '7.0', 'unit' => '10^9/L']], now()->subDays(1));
    phase4cRunAnalysis($reportA, $setA, $user, [phase4cNormalizedRow($setA->results->first()->id, 'wbc', 'WBC', 14.0, '10^9/L', 4, 11, 'high')]);
    phase4cRunAnalysis($reportB, $setB, $user, [phase4cNormalizedRow($setB->results->first()->id, 'wbc', 'WBC', 7.0, '10^9/L', 4, 11, 'normal')]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'wbc');
    expect($analyte['trend'])->toBe('DECREASED');
});

test('a numerically unchanged value is classified as stable', function () {
    $user = User::factory()->create();
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '13.0', 'unit' => 'g/dL']], now()->subDays(20));
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '13.0', 'unit' => 'g/dL']], now()->subDays(1));
    phase4cRunAnalysis($reportA, $setA, $user, [phase4cNormalizedRow($setA->results->first()->id, 'hemoglobin', 'Hemoglobin', 13.0, 'g/dL', 12, 16, 'normal')]);
    phase4cRunAnalysis($reportB, $setB, $user, [phase4cNormalizedRow($setB->results->first()->id, 'hemoglobin', 'Hemoglobin', 13.0, 'g/dL', 12, 16, 'normal')]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'hemoglobin');
    expect($analyte['trend'])->toBe('STABLE')
        ->and($analyte['reference_trend'])->toBe('REMAINED_WITHIN_REFERENCE');
});

test('an analyte present in only one report is insufficient data, but does not discard the whole comparison', function () {
    $user = User::factory()->create();
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [
        ['label' => 'HGB', 'value' => '9.2', 'unit' => 'g/dL', 'canonical_analyte_id_hint' => 'hemoglobin'],
        ['label' => 'MCV', 'value' => '72', 'unit' => 'fL', 'canonical_analyte_id_hint' => 'mcv'],
    ], now()->subDays(20));
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [
        ['label' => 'HGB', 'value' => '10.5', 'unit' => 'g/dL', 'canonical_analyte_id_hint' => 'hemoglobin'],
    ], now()->subDays(1));

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    $analytes = collect($response->json('data.comparison.analytes'))->keyBy('analyte_id');
    expect($analytes->has('hemoglobin'))->toBeTrue()
        ->and($analytes->has('mcv'))->toBeTrue()
        ->and($analytes['mcv']['trend'])->toBe('INSUFFICIENT_DATA')
        ->and($analytes['mcv']['points'][1]['value'])->toBeNull()
        ->and($analytes['mcv']['points'][0]['value'])->toEqual(72.0);
});

test('the same analyte reported with different units is marked not comparable rather than silently compared', function () {
    $user = User::factory()->create();
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::LiverFunction, [
        ['label' => 'Bilirubin', 'value' => '1.2', 'unit' => 'mg/dL', 'canonical_analyte_id_hint' => 'total_bilirubin'],
    ], now()->subDays(20));
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::LiverFunction, [
        ['label' => 'Bilirubin', 'value' => '20.5', 'unit' => 'umol/L', 'canonical_analyte_id_hint' => 'total_bilirubin'],
    ], now()->subDays(1));

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'total_bilirubin');
    expect($analyte['trend'])->toBe('NOT_COMPARABLE')
        ->and($analyte['comparable'])->toBeFalse();
});

test('CBC reports compare correctly with real values across three timepoints', function () {
    $user = User::factory()->create();
    [$r1, $s1] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2', 'unit' => 'g/dL', 'reference_range' => '12-16']], now()->subDays(200));
    // r2 is verified but never analyzed - matched only via the OCR canonical hint
    // fallback (see BuildReportComparison::resolveAnalyteIdentity), exactly the
    // degraded-but-still-usable case documented for reports with no succeeded Analysis.
    [$r2, $s2] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '10.5', 'unit' => 'g/dL', 'reference_range' => '12-16', 'canonical_analyte_id_hint' => 'hemoglobin']], now()->subDays(100));
    [$r3, $s3] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '12.1', 'unit' => 'g/dL', 'reference_range' => '12-16']], now()->subDays(1));
    phase4cRunAnalysis($r1, $s1, $user, [phase4cNormalizedRow($s1->results->first()->id, 'hemoglobin', 'Hemoglobin', 9.2, 'g/dL', 12, 16, 'low')]);
    phase4cRunAnalysis($r3, $s3, $user, [phase4cNormalizedRow($s3->results->first()->id, 'hemoglobin', 'Hemoglobin', 12.1, 'g/dL', 12, 16, 'normal')]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$r1->getKey(), $r2->getKey(), $r3->getKey()], 'language' => 'en',
    ])->assertOk()->assertJsonPath('data.comparison.category', 'CBC');

    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'hemoglobin');
    expect(collect($analyte['points'])->pluck('value')->all())->toBe([9.2, 10.5, 12.1])
        ->and($analyte['trend'])->toBe('INCREASED');
});

test('DIABETES reports compare correctly', function () {
    $user = User::factory()->create();
    [$r1, $s1] = phase4cVerifiedReport($user, ReportTestCategory::Diabetes, [['label' => 'HbA1c', 'value' => '7.5', 'unit' => '%', 'canonical_analyte_id_hint' => 'hba1c']], now()->subDays(60));
    [$r2, $s2] = phase4cVerifiedReport($user, ReportTestCategory::Diabetes, [['label' => 'HbA1c', 'value' => '6.2', 'unit' => '%', 'canonical_analyte_id_hint' => 'hba1c']], now()->subDays(1));

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$r1->getKey(), $r2->getKey()], 'language' => 'en',
    ])->assertOk()->assertJsonPath('data.comparison.category', 'DIABETES');

    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'hba1c');
    expect($analyte['trend'])->toBe('DECREASED');
});

test('LIVER_FUNCTION reports compare correctly', function () {
    $user = User::factory()->create();
    [$r1, $s1] = phase4cVerifiedReport($user, ReportTestCategory::LiverFunction, [['label' => 'ALT', 'value' => '65', 'unit' => 'U/L', 'canonical_analyte_id_hint' => 'alt']], now()->subDays(60));
    [$r2, $s2] = phase4cVerifiedReport($user, ReportTestCategory::LiverFunction, [['label' => 'ALT', 'value' => '30', 'unit' => 'U/L', 'canonical_analyte_id_hint' => 'alt']], now()->subDays(1));

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$r1->getKey(), $r2->getKey()], 'language' => 'en',
    ])->assertOk()->assertJsonPath('data.comparison.category', 'LIVER_FUNCTION');

    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'alt');
    expect($analyte['trend'])->toBe('DECREASED');
});

test('the latest verified version is used for numeric values but an older versions analysis is never attached to it', function () {
    $user = User::factory()->create();
    [$report, $setV1] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.0', 'unit' => 'g/dL', 'canonical_analyte_id_hint' => 'hemoglobin']]);
    phase4cRunAnalysis($report, $setV1, $user, [phase4cNormalizedRow($setV1->results->first()->id, 'hemoglobin', 'Hemoglobin', 9.0, 'g/dL', 12, 16, 'low')]);

    $setV2 = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 2, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 29, 'patient_sex' => 'FEMALE', 'idempotency_key' => 'phase4c-v2-'.fake()->uuid(),
        'category_gate_status' => 'MATCH', 'category_gate_category' => 'CBC', 'confirmed_at' => now(),
    ]);
    $setV2->results()->create(['label' => 'HGB', 'value' => '13.5', 'unit' => 'g/dL', 'canonical_analyte_id_hint' => 'hemoglobin', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1]);

    [$otherReport, $otherSet] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '13.0', 'unit' => 'g/dL', 'canonical_analyte_id_hint' => 'hemoglobin']]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$report->getKey(), $otherReport->getKey()], 'language' => 'en',
    ])->assertOk();

    $reportEntry = collect($response->json('data.comparison.reports'))->firstWhere('id', $report->getKey());
    $analyte = collect($response->json('data.comparison.analytes'))->firstWhere('analyte_id', 'hemoglobin');
    $point = collect($analyte['points'])->firstWhere('report_id', $report->getKey());

    expect($reportEntry['verified_result_set_version'])->toBe(2)
        ->and($reportEntry['analysis_id'])->toBeNull()
        ->and($point['value'])->toBe(13.5);
});

test('the response contract matches the documented shape', function () {
    $user = User::factory()->create();
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2', 'unit' => 'g/dL', 'reference_range' => '12-16']]);
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '12.1', 'unit' => 'g/dL', 'reference_range' => '12-16']]);
    phase4cRunAnalysis($reportA, $setA, $user, [phase4cNormalizedRow($setA->results->first()->id, 'hemoglobin', 'Hemoglobin', 9.2, 'g/dL', 12, 16, 'low')]);
    phase4cRunAnalysis($reportB, $setB, $user, [phase4cNormalizedRow($setB->results->first()->id, 'hemoglobin', 'Hemoglobin', 12.1, 'g/dL', 12, 16, 'normal')]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk()->assertJsonStructure([
        'success',
        'data' => [
            'comparison' => [
                'category', 'generated_at',
                'reports' => ['*' => ['id', 'sequence', 'date', 'status', 'verified_result_set_version', 'analysis_id', 'analysis_status']],
                'analytes' => ['*' => ['analyte_id', 'display_name', 'unit', 'comparable', 'points', 'trend', 'reference_trend']],
                'kbs_timeline' => ['*' => ['report_id', 'sequence', 'analysis_id', 'analysis_status', 'conclusions', 'missing_information']],
            ],
            'ai_context' => ['status', 'language', 'content'],
        ],
    ]);
});

test('comparison performs no OCR or KBS side effects and dispatches no jobs', function () {
    config(['ai.gemini.enabled' => false]);
    $user = User::factory()->create();
    [$reportA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2']]);
    [$reportB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '10.0']]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    Queue::assertNothingPushed();
    Queue::assertNotPushed(ProcessReportOcr::class);
    Queue::assertNotPushed(ProcessReportAnalysis::class);
});

test('comparison performs no database mutation - critical no side effect regression test', function () {
    config(['ai.gemini.enabled' => false]);
    $user = User::factory()->create();
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.2', 'unit' => 'g/dL']]);
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '10.0', 'unit' => 'g/dL']]);
    phase4cRunAnalysis($reportA, $setA, $user, [phase4cNormalizedRow($setA->results->first()->id, 'hemoglobin', 'Hemoglobin', 9.2, 'g/dL', 12, 16, 'low')]);

    $counts = [
        'reports' => Report::query()->count(),
        'verified_result_sets' => VerifiedResultSet::query()->count(),
        'verified_results' => VerifiedResult::query()->count(),
        'analyses' => Analysis::query()->count(),
        'analysis_conclusions' => AnalysisConclusion::query()->count(),
        'rule_traces' => RuleTrace::query()->count(),
        'quiz_sessions' => QuizSession::query()->count(),
    ];

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect(Report::query()->count())->toBe($counts['reports'])
        ->and(VerifiedResultSet::query()->count())->toBe($counts['verified_result_sets'])
        ->and(VerifiedResult::query()->count())->toBe($counts['verified_results'])
        ->and(Analysis::query()->count())->toBe($counts['analyses'])
        ->and(AnalysisConclusion::query()->count())->toBe($counts['analysis_conclusions'])
        ->and(RuleTrace::query()->count())->toBe($counts['rule_traces'])
        ->and(QuizSession::query()->count())->toBe($counts['quiz_sessions']);
});
