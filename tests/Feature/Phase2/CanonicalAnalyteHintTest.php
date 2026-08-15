<?php

use App\Enums\ExtractionJobStatus;
use App\Enums\PatientSex;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Models\ExtractedResult;
use App\Models\ExtractionJob;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Kbs\KbsRequestMapper;

/**
 * Tests for the OCR->verified-results canonical-identity hint contract (Task 2,
 * category C: user-edit invalidation/revalidation). VerifyReport::canonicalAnalyteHintFor()
 * carries OCR's resolved kbs_test_id forward into verified_results.canonical_analyte_id_hint
 * only when the verified label is, character-for-character (case/whitespace aside), the
 * exact label OCR resolved it from -- any label edit clears it. A unit-only edit does NOT
 * clear it here (Laravel has no medical/unit knowledge); KBS is solely responsible for
 * detecting a hint that is stale because of a unit change (proven separately in
 * kbs/tests/test_analyte_identity.py::HintResolutionTests).
 */
function hintFixture(User $user, ReportTestCategory $category = ReportTestCategory::Cbc): array
{
    $report = Report::factory()->for($user)->forCategory($category)->create(['status' => ReportStatus::NeedsReview]);
    $file = ReportFile::factory()->for($report)->create();
    $job = ExtractionJob::factory()->for($report)->for($file, 'reportFile')->create(['status' => ExtractionJobStatus::Succeeded]);
    $wbc = ExtractedResult::factory()->for($job, 'extractionJob')->for($report)->create([
        'raw_label' => 'WBC Count', 'raw_value' => '6.5', 'raw_unit' => '*10^3/uL',
        'ocr_canonical_name' => 'White Blood Cells / Leukocytes', 'ocr_test_code' => 'CBC_WBC', 'ocr_kbs_test_id' => 'wbc',
    ]);
    $noHint = ExtractedResult::factory()->for($job, 'extractionJob')->for($report)->create([
        'raw_label' => 'HGB', 'raw_value' => '13.5', 'raw_unit' => 'g/dL',
    ]);

    return [$report, $wbc, $noHint];
}

function hintToken(User $user): string
{
    return $user->createToken('hint-test')->plainTextToken;
}

test('unchanged verified label carries the OCR kbs_test_id forward as a hint', function () {
    $owner = User::factory()->create();
    [$report, $wbc] = hintFixture($owner);

    $response = $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-unchanged-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => $wbc->id, 'label' => 'WBC Count', 'value' => '6.5', 'unit' => '*10^3/uL', 'reference_range' => '4-11'],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();

    $set = VerifiedResultSet::query()->findOrFail($response->json('data.verified_result_set.id'));
    expect($set->results()->first()->canonical_analyte_id_hint)->toBe('wbc');
});

test('editing the verified label clears the OCR hint', function () {
    $owner = User::factory()->create();
    [$report, $wbc] = hintFixture($owner);

    $response = $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-label-edited-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            // Same source row, but the user retyped the label to something unrelated.
            ['source_extracted_result_id' => $wbc->id, 'label' => 'Sodium', 'value' => '6.5', 'unit' => '*10^3/uL', 'reference_range' => '4-11'],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();

    $set = VerifiedResultSet::query()->findOrFail($response->json('data.verified_result_set.id'));
    expect($set->results()->first()->canonical_analyte_id_hint)->toBeNull();
});

test('editing only the unit with the label unchanged still carries the hint forward from laravel -- KBS is responsible for revalidating it', function () {
    $owner = User::factory()->create();
    [$report, $wbc] = hintFixture($owner);

    $response = $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-unit-edited-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => $wbc->id, 'label' => 'WBC Count', 'value' => '6.5', 'unit' => 'bananas', 'reference_range' => '4-11'],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();

    $set = VerifiedResultSet::query()->findOrFail($response->json('data.verified_result_set.id'));
    expect($set->results()->first()->canonical_analyte_id_hint)->toBe('wbc');
});

test('a case and whitespace only label difference still counts as unchanged', function () {
    $owner = User::factory()->create();
    [$report, $wbc] = hintFixture($owner);

    $response = $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-case-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => $wbc->id, 'label' => '  wbc count  ', 'value' => '6.5', 'unit' => '*10^3/uL', 'reference_range' => '4-11'],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();

    $set = VerifiedResultSet::query()->findOrFail($response->json('data.verified_result_set.id'));
    expect($set->results()->first()->canonical_analyte_id_hint)->toBe('wbc');
});

test('a source row with no OCR kbs_test_id produces no hint', function () {
    $owner = User::factory()->create();
    [$report, , $noHint] = hintFixture($owner);

    $response = $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-no-source-hint-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => $noHint->id, 'label' => 'HGB', 'value' => '13.5', 'unit' => 'g/dL', 'reference_range' => null],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();

    $set = VerifiedResultSet::query()->findOrFail($response->json('data.verified_result_set.id'));
    expect($set->results()->first()->canonical_analyte_id_hint)->toBeNull();
});

test('a manually added row with no source extracted result produces no hint', function () {
    $owner = User::factory()->create();
    [$report] = hintFixture($owner);

    $response = $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-manual-row-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => null, 'label' => 'MCV', 'value' => '88', 'unit' => 'fL', 'reference_range' => '80-100'],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();

    $set = VerifiedResultSet::query()->findOrFail($response->json('data.verified_result_set.id'));
    expect($set->results()->first()->canonical_analyte_id_hint)->toBeNull();
});

test('KbsRequestMapper forwards the persisted hint as analyte_id_hint in the wire payload', function () {
    $owner = User::factory()->create();
    [$report, $wbc] = hintFixture($owner);
    $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-wire-payload-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => $wbc->id, 'label' => 'WBC Count', 'value' => '6.5', 'unit' => '*10^3/uL', 'reference_range' => '4-11'],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();
    $set = VerifiedResultSet::query()->where('report_id', $report->id)->with('results')->firstOrFail();

    $payload = (new KbsRequestMapper)->mapForPreflight($report->test_category->value, $set);

    expect($payload['results'][0]['label'])->toBe('WBC Count')
        ->and($payload['results'][0]['analyte_id_hint'])->toBe('wbc');
});

test('KbsRequestMapper forwards a null analyte_id_hint when no hint was carried forward', function () {
    $owner = User::factory()->create();
    [$report, , $noHint] = hintFixture($owner);
    $this->withToken(hintToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', [
        'idempotency_key' => 'hint-wire-null-key',
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => $noHint->id, 'label' => 'HGB', 'value' => '13.5', 'unit' => 'g/dL', 'reference_range' => null],
        ],
        'excluded_source_result_ids' => [],
    ])->assertCreated();
    $set = VerifiedResultSet::query()->where('report_id', $report->id)->with('results')->firstOrFail();

    $payload = (new KbsRequestMapper)->mapForPreflight($report->test_category->value, $set);

    expect($payload['results'][0]['analyte_id_hint'])->toBeNull();
});
