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

function verificationFixture(User $user, ReportTestCategory $category = ReportTestCategory::Cbc): array
{
    $report = Report::factory()->for($user)->forCategory($category)->create(['status' => ReportStatus::NeedsReview]);
    $file = ReportFile::factory()->for($report)->create();
    $job = ExtractionJob::factory()->for($report)->for($file, 'reportFile')->create(['status' => ExtractionJobStatus::Succeeded]);
    $hgb = ExtractedResult::factory()->for($job, 'extractionJob')->for($report)->create([
        'raw_label' => 'HGB', 'raw_value' => '12.0', 'raw_unit' => 'g/dL', 'confidence' => 0.72,
    ]);
    $wbc = ExtractedResult::factory()->for($job, 'extractionJob')->for($report)->create([
        'raw_label' => 'WBC', 'raw_value' => '7.5', 'raw_unit' => '10^9/L', 'confidence' => 0.98,
    ]);

    return [$report, $hgb, $wbc];
}

function verificationToken(User $user): string
{
    return $user->createToken('verification-test')->plainTextToken;
}

function verificationPayload(ExtractedResult $hgb, ExtractedResult $wbc, string $key = 'verification-request-0001'): array
{
    return [
        'idempotency_key' => $key,
        'patient_age_years' => 24.5,
        'patient_sex' => PatientSex::Female->value,
        'rows' => [
            ['source_extracted_result_id' => $hgb->id, 'label' => 'HGB', 'value' => '13.1', 'unit' => 'g/dL', 'reference_range' => '12-16'],
            ['source_extracted_result_id' => $wbc->id, 'label' => 'WBC', 'value' => '7.5', 'unit' => '10^9/L', 'reference_range' => null],
            ['source_extracted_result_id' => null, 'label' => 'MCV', 'value' => '88', 'unit' => 'fL', 'reference_range' => '80-100'],
        ],
        'excluded_source_result_ids' => [],
    ];
}

test('owner confirms patient context and reviewed rows atomically as version one', function () {
    $owner = User::factory()->regular()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $rawBefore = $report->extractedResults()->orderBy('id')->get()->map->getAttributes()->all();

    $response = $this->withToken(verificationToken($owner))
        ->postJson('/api/v1/reports/'.$report->id.'/verification', verificationPayload($hgb, $wbc));

    $response->assertCreated()
        ->assertJsonPath('data.report_status', 'VERIFIED')
        ->assertJsonPath('data.verified_result_set.version', 1)
        ->assertJsonPath('data.verified_result_set.patient_age_years', 24.5)
        ->assertJsonPath('data.verified_result_set.patient_sex', 'FEMALE')
        ->assertJsonPath('data.verified_result_set.rows.0.was_modified', true)
        ->assertJsonPath('data.verified_result_set.rows.2.was_added_manually', true)
        ->assertJsonPath('data.verified_result_set.category_gate.status', 'MATCH')
        ->assertJsonPath('data.next_actions.0', 'VIEW_ANALYSIS_RESULT')
        ->assertJsonPath('data.kbs_integration_ready', true);

    expect($report->refresh()->status)->toBe(ReportStatus::Verified)
        ->and($report->patient_age_years)->toBe(24.5)
        ->and($report->patient_sex)->toBe(PatientSex::Female)
        ->and($report->verifiedResultSets()->count())->toBe(1)
        ->and($report->extractedResults()->orderBy('id')->get()->map->getAttributes()->all())->toBe($rawBefore);
});

test('confirmation is idempotent for double taps', function () {
    $owner = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $payload = verificationPayload($hgb, $wbc, 'same-double-tap-key');
    $token = verificationToken($owner);

    $firstId = $this->withToken($token)->postJson('/api/v1/reports/'.$report->id.'/verification', $payload)
        ->assertCreated()->json('data.verified_result_set.id');
    $secondId = $this->withToken($token)->postJson('/api/v1/reports/'.$report->id.'/verification', $payload)
        ->assertCreated()->json('data.verified_result_set.id');

    expect($secondId)->toBe($firstId)->and($report->verifiedResultSets()->count())->toBe(1);
});

test('later confirmation creates immutable version two', function () {
    $owner = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $token = verificationToken($owner);
    $payloadOne = verificationPayload($hgb, $wbc, 'version-one-key');
    $payloadTwo = verificationPayload($hgb, $wbc, 'version-two-key');
    $payloadTwo['rows'][0]['value'] = '14.2';

    $this->withToken($token)->postJson('/api/v1/reports/'.$report->id.'/verification', $payloadOne)->assertCreated();
    $this->withToken($token)->postJson('/api/v1/reports/'.$report->id.'/verification', $payloadTwo)
        ->assertCreated()->assertJsonPath('data.verified_result_set.version', 2);

    $sets = VerifiedResultSet::query()->where('report_id', $report->id)->with('results')->orderBy('version')->get();
    expect($sets)->toHaveCount(2)
        ->and($sets[0]->results[0]->value)->toBe('13.1')
        ->and($sets[1]->results[0]->value)->toBe('14.2');
});

test('excluded source rows are audited but not copied into verified rows', function () {
    $owner = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $payload = verificationPayload($hgb, $wbc);
    $payload['rows'] = [$payload['rows'][0]];
    $payload['excluded_source_result_ids'] = [$wbc->id];

    $this->withToken(verificationToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', $payload)
        ->assertCreated()
        ->assertJsonPath('data.verified_result_set.excluded_source_result_ids.0', $wbc->id)
        ->assertJsonCount(1, 'data.verified_result_set.rows');
});

test('other users and unauthenticated clients cannot verify a report', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $uri = '/api/v1/reports/'.$report->id.'/verification';

    $this->postJson($uri, verificationPayload($hgb, $wbc))->assertUnauthorized();
    $this->withToken(verificationToken($other))->postJson($uri, verificationPayload($hgb, $wbc))->assertForbidden();
    expect($report->verifiedResultSets()->count())->toBe(0);
});

test('non reviewable reports cannot be verified', function () {
    $owner = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $report->update(['status' => ReportStatus::Processing]);

    $this->withToken(verificationToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', verificationPayload($hgb, $wbc))
        ->assertConflict()->assertJsonPath('error_code', 'REPORT_NOT_REVIEWABLE');
});

test('patient context and required verified row fields are validated before persistence', function () {
    $owner = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $payload = verificationPayload($hgb, $wbc);
    $payload['patient_age_years'] = 0;
    $payload['patient_sex'] = 'UNKNOWN';
    $payload['rows'][0]['label'] = '';

    $this->withToken(verificationToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['patient_age_years', 'patient_sex', 'rows.0.label']);
    expect($report->verifiedResultSets()->count())->toBe(0);
});

test('category gate returns mismatch, ambiguity, and match from authoritative KBS aliases', function (array $labels, string $expected) {
    $owner = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $payload = verificationPayload($hgb, $wbc, 'category-'.strtolower($expected).'-key');
    $payload['rows'] = collect($labels)->map(fn (string $label, int $index): array => [
        'source_extracted_result_id' => $index === 0 ? $hgb->id : ($index === 1 ? $wbc->id : null),
        'label' => $label, 'value' => '1', 'unit' => 'x', 'reference_range' => null,
    ])->all();

    $this->withToken(verificationToken($owner))->postJson('/api/v1/reports/'.$report->id.'/verification', $payload)
        ->assertCreated()->assertJsonPath('data.verified_result_set.category_gate.status', $expected);
})->with([
    'match' => [['HGB', 'WBC'], 'MATCH'],
    'mixed ambiguity' => [['HGB', 'WBC', 'ALT'], 'AMBIGUOUS'],
    'unsupported majority ambiguity' => [['HGB', 'WBC', 'Creatinine', 'Urea', 'Sodium', 'Potassium', 'Chloride'], 'AMBIGUOUS'],
    'mismatch' => [['HbA1c'], 'MISMATCH'],
]);

test('students receive both role aware handoff choices while regular users receive only direct result', function (string $role, array $expected) {
    $user = $role === 'student' ? User::factory()->student('4')->create() : User::factory()->regular()->create();
    [$report, $hgb, $wbc] = verificationFixture($user);

    $this->withToken(verificationToken($user))->postJson('/api/v1/reports/'.$report->id.'/verification', verificationPayload($hgb, $wbc))
        ->assertCreated()->assertJsonPath('data.next_actions', $expected);
})->with([
    'regular' => ['regular', ['VIEW_ANALYSIS_RESULT']],
    'student' => ['student', ['VIEW_ANALYSIS_RESULT', 'TAKE_QUIZ_FIRST']],
]);

test('owner can retrieve latest and historical verified versions', function () {
    $owner = User::factory()->create();
    [$report, $hgb, $wbc] = verificationFixture($owner);
    $token = verificationToken($owner);
    $this->withToken($token)->postJson('/api/v1/reports/'.$report->id.'/verification', verificationPayload($hgb, $wbc, 'retrieve-one-key'))->assertCreated();
    $this->withToken($token)->postJson('/api/v1/reports/'.$report->id.'/verification', verificationPayload($hgb, $wbc, 'retrieve-two-key'))->assertCreated();

    $this->withToken($token)->getJson('/api/v1/reports/'.$report->id.'/verification')->assertOk()->assertJsonPath('data.verified_result_set.version', 2);
    $this->withToken($token)->getJson('/api/v1/reports/'.$report->id.'/verification?version=1')->assertOk()->assertJsonPath('data.verified_result_set.version', 1);
});
