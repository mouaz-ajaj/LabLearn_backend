<?php

use App\Enums\ExtractionJobStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\ProcessReportOcr;
use App\Models\ExtractionJob;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\OcrResultMapper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
});

function phaseTwoQueuedExtraction(ReportTestCategory $category = ReportTestCategory::Cbc): array
{
    $user = User::factory()->create();
    $report = Report::factory()
        ->for($user)
        ->forCategory($category)
        ->create(['status' => ReportStatus::Queued]);
    $path = 'reports/'.$user->getKey().'/'.$report->getKey().'/report.png';
    Storage::disk('local')->put($path, 'private report bytes');
    $reportFile = ReportFile::factory()->for($report)->create(['storage_path' => $path]);
    $extractionJob = ExtractionJob::factory()->for($report)->for($reportFile, 'reportFile')->create();

    return [$report, $reportFile, $extractionJob];
}

function phaseTwoOcrResponse(
    string $rawLabel = 'Hgb',
    string $rawValue = '13.5',
    string $rawUnit = 'g/dL',
    string $rawReference = '12.0 - 16.0',
): array {
    return [
        'success' => true,
        'request_id' => 'ocr-test-1',
        'api_version' => '1.0.0',
        'response_contract_version' => '2.0',
        'response_profile' => ['name' => 'full', 'version' => '1.0'],
        'data' => [
            'document' => [
                'document_type' => 'image',
                'original_filename' => 'report.png',
                'status' => 'success',
                'classification' => 'image',
                'language' => 'auto',
                'page_count' => 1,
            ],
            'quality' => ['score' => 0.93, 'grade' => 'excellent', 'review_required' => false],
            'pages' => [],
            'tests' => [[
                'row_id' => 'page-0001-row-0001',
                'page_number' => 1,
                'source_type' => 'ocr_image',
                'original_test_name' => $rawLabel,
                'canonical_name' => $rawLabel,
                'test_code' => 'RAW_TEST',
                'value' => ['raw' => $rawValue, 'normalized' => $rawValue, 'parsed' => (float) $rawValue],
                'unit' => ['raw' => $rawUnit, 'normalized' => $rawUnit],
                'reference_range' => ['raw' => $rawReference, 'normalized' => $rawReference],
                'confidence' => ['result_value' => ['calibrated' => 0.97]],
                'quality' => [],
                'review_status' => 'accepted',
                'warnings' => [],
            ]],
            'review_queue' => [],
            'warnings' => [],
        ],
        'error' => null,
    ];
}

test('OCR results are stored and each supported category reaches needs review', function (
    ReportTestCategory $category,
    string $rawLabel,
    string $rawValue,
    string $rawUnit,
    string $rawReference,
) {
    [$report, , $extractionJob] = phaseTwoQueuedExtraction($category);
    Http::fake(['*' => Http::response(
        phaseTwoOcrResponse($rawLabel, $rawValue, $rawUnit, $rawReference),
        200,
        ['X-Request-ID' => 'ocr-test-1'],
    )]);

    (new ProcessReportOcr($extractionJob->getKey()))->handle(app(OcrClient::class), app(OcrResultMapper::class));

    $extractionJob->refresh();
    expect($extractionJob->status)->toBe(ExtractionJobStatus::Succeeded)
        ->and($extractionJob->progress)->toBe(100)
        ->and($extractionJob->engine_version)->toBe('1.0.0')
        ->and($extractionJob->raw_response_json['request_id'])->toBe('ocr-test-1')
        ->and($report->refresh()->test_category)->toBe($category)
        ->and($report->status)->toBe(ReportStatus::NeedsReview)
        ->and($extractionJob->extractedResults()->count())->toBe(1);

    $result = $extractionJob->extractedResults()->firstOrFail();
    expect($result->raw_label)->toBe($rawLabel)
        ->and($result->raw_value)->toBe($rawValue)
        ->and($result->raw_unit)->toBe($rawUnit)
        ->and($result->raw_reference)->toBe($rawReference)
        ->and($result->page)->toBe(1)
        ->and($result->confidence)->toBe(0.97)
        ->and($result->bbox_json)->toBeNull();
})->with([
    'CBC raw row' => [ReportTestCategory::Cbc, 'Hgb', '13.5', 'g/dL', '12.0 - 16.0'],
    'diabetes raw row' => [ReportTestCategory::Diabetes, 'HbA1c', '6.2', '%', '4.0 - 5.6'],
    'liver function raw row' => [ReportTestCategory::LiverFunction, 'ALT', '42', 'U/L', '7 - 56'],
]);
test('OCR canonical interpretation is stored alongside raw fields without overwriting them', function () {
    // Proves the OCR->Laravel contract (Task 2, category A): canonical_name,
    // test_code, kbs_test_id, and normalized value/unit/reference are no longer
    // discarded after storage -- they land in dedicated ocr_* columns -- while the
    // original raw_* columns remain exactly what OCR reported as raw, untouched.
    [, , $extractionJob] = phaseTwoQueuedExtraction();
    $response = phaseTwoOcrResponse('WBC Count', '6.5', '*10^3/uL', '4-11');
    $response['data']['tests'][0]['canonical_name'] = 'White Blood Cells / Leukocytes';
    $response['data']['tests'][0]['test_code'] = 'CBC_WBC';
    $response['data']['tests'][0]['kbs_test_id'] = 'wbc';
    $response['data']['tests'][0]['value']['normalized'] = '6.5';
    $response['data']['tests'][0]['unit']['normalized'] = 'K/uL';
    $response['data']['tests'][0]['reference_range']['normalized'] = '4 - 11';
    Http::fake(['*' => Http::response($response, 200, ['X-Request-ID' => 'ocr-test-1'])]);

    (new ProcessReportOcr($extractionJob->getKey()))->handle(app(OcrClient::class), app(OcrResultMapper::class));

    $result = $extractionJob->extractedResults()->firstOrFail();
    expect($result->raw_label)->toBe('WBC Count')
        ->and($result->raw_value)->toBe('6.5')
        ->and($result->raw_unit)->toBe('*10^3/uL')
        ->and($result->raw_reference)->toBe('4-11')
        ->and($result->ocr_canonical_name)->toBe('White Blood Cells / Leukocytes')
        ->and($result->ocr_test_code)->toBe('CBC_WBC')
        ->and($result->ocr_kbs_test_id)->toBe('wbc')
        ->and($result->ocr_normalized_value)->toBe('6.5')
        ->and($result->ocr_normalized_unit)->toBe('K/uL')
        ->and($result->ocr_normalized_reference)->toBe('4 - 11');
});

test('OCR rows with no canonical interpretation leave the new columns null', function () {
    // Backward-compatibility / legacy-row proof (Task 2, category G): a row OCR
    // could not resolve at all (no canonical_name/test_code/kbs_test_id in the OCR
    // response) must not fabricate any of the new ocr_* columns.
    [, , $extractionJob] = phaseTwoQueuedExtraction();
    $response = phaseTwoOcrResponse('Unrecognized Squiggle', '1', '', '');
    unset(
        $response['data']['tests'][0]['canonical_name'],
        $response['data']['tests'][0]['test_code'],
        $response['data']['tests'][0]['unit']['normalized'],
    );
    Http::fake(['*' => Http::response($response, 200, ['X-Request-ID' => 'ocr-test-1'])]);

    (new ProcessReportOcr($extractionJob->getKey()))->handle(app(OcrClient::class), app(OcrResultMapper::class));

    $result = $extractionJob->extractedResults()->firstOrFail();
    expect($result->ocr_canonical_name)->toBeNull()
        ->and($result->ocr_test_code)->toBeNull()
        ->and($result->ocr_kbs_test_id)->toBeNull()
        ->and($result->ocr_normalized_unit)->toBeNull();
});

test('an invalid OCR response fails safely without persisting rows', function () {
    [$report, , $extractionJob] = phaseTwoQueuedExtraction();
    Http::fake(['*' => Http::response(['success' => true, 'unexpected' => []])]);

    (new ProcessReportOcr($extractionJob->getKey()))->handle(app(OcrClient::class), app(OcrResultMapper::class));

    expect($extractionJob->refresh()->status)->toBe(ExtractionJobStatus::Failed)
        ->and($extractionJob->error_code)->toBe('OCR_INVALID_RESPONSE')
        ->and($extractionJob->safe_error_message)->toBe('The OCR service returned an invalid response.')
        ->and($extractionJob->extractedResults()->count())->toBe(0)
        ->and($report->refresh()->status)->toBe(ReportStatus::Failed);
});

test('a permanent OCR validation failure marks the job and report failed', function () {
    [$report, , $extractionJob] = phaseTwoQueuedExtraction();
    Http::fake(['*' => Http::response([
        'success' => false,
        'request_id' => 'ocr-invalid',
        'error' => ['code' => 'unsupported_file_type', 'message' => 'internal detail'],
    ], 415)]);

    (new ProcessReportOcr($extractionJob->getKey()))->handle(app(OcrClient::class), app(OcrResultMapper::class));

    expect($extractionJob->refresh()->status)->toBe(ExtractionJobStatus::Failed)
        ->and($extractionJob->error_code)->toBe('OCR_PROCESSING_FAILED')
        ->and($extractionJob->safe_error_message)->not->toContain('internal detail')
        ->and($report->refresh()->status)->toBe(ReportStatus::Failed);
});

test('an exhausted retryable OCR timeout marks the job and report failed', function () {
    [$report, , $extractionJob] = phaseTwoQueuedExtraction();
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    $queuedJob = new ProcessReportOcr($extractionJob->getKey());

    try {
        $queuedJob->handle(app(OcrClient::class), app(OcrResultMapper::class));
        $this->fail('Expected a retryable OCR exception.');
    } catch (OcrException $exception) {
        expect($exception->errorCode)->toBe('OCR_TIMEOUT')
            ->and($exception->retryable)->toBeTrue();
        $queuedJob->failed($exception);
    }

    expect($extractionJob->refresh()->status)->toBe(ExtractionJobStatus::Failed)
        ->and($extractionJob->error_code)->toBe('OCR_TIMEOUT')
        ->and($report->refresh()->status)->toBe(ReportStatus::Failed);
});
