<?php

use App\Enums\ExtractionJobStatus;
use App\Enums\PatientSex;
use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\ProcessReportOcr;
use App\Models\ExtractedResult;
use App\Models\ExtractionJob;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

function phaseTwoToken(User $user): string
{
    return $user->createToken('phase-two-test')->plainTextToken;
}

function phaseTwoPngUpload(string $name = 'report.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL2WQAAAABJRU5ErkJggg==', true);

    return UploadedFile::fake()->createWithContent($name, $png);
}

function phaseTwoReportWithFile(
    User $user,
    ReportTestCategory $category = ReportTestCategory::Cbc,
    ReportSourceType $sourceType = ReportSourceType::Image,
): array {
    $report = Report::factory()
        ->for($user)
        ->forCategory($category)
        ->create(['source_type' => $sourceType]);
    $reportFile = ReportFile::factory()->for($report)->create();

    return [$report, $reportFile];
}

test('the report category contract contains exactly the supported values', function () {
    expect(array_column(ReportTestCategory::cases(), 'value'))->toBe([
        'CBC',
        'DIABETES',
        'LIVER_FUNCTION',
    ]);
});
test('an authenticated user can create each supported report category', function (ReportTestCategory $category, ReportSourceType $sourceType) {
    $user = User::factory()->create();

    $this->withToken(phaseTwoToken($user))
        ->postJson('/api/v1/reports', [
            'test_category' => $category->value,
            'source_type' => $sourceType->value,
        ])
        ->assertCreated()
        ->assertJsonPath('data.report.status', 'UPLOADED')
        ->assertJsonPath('data.report.test_category', $category->value)
        ->assertJsonPath('data.report.source_type', $sourceType->value);

    $report = $user->reports()->sole();
    expect($report->test_category)->toBe($category)
        ->and($report->source_type)->toBe($sourceType);
})->with([
    'CBC image' => [ReportTestCategory::Cbc, ReportSourceType::Image],
    'diabetes image' => [ReportTestCategory::Diabetes, ReportSourceType::Image],
    'liver function PDF' => [ReportTestCategory::LiverFunction, ReportSourceType::Pdf],
]);

test('unknown, aliased, lowercase, and malformed report categories are rejected', function (mixed $category) {
    $user = User::factory()->create();

    $this->withToken(phaseTwoToken($user))
        ->postJson('/api/v1/reports', [
            'test_category' => $category,
            'source_type' => ReportSourceType::Image->value,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors('test_category');
})->with([
    'thyroid' => 'THYROID',
    'kidney function' => 'KIDNEY_FUNCTION',
    'glucose alias' => 'GLUCOSE',
    'HbA1c alias' => 'HBA1C',
    'liver alias' => 'LIVER',
    'diabetic alias' => 'DIABETIC',
    'other' => 'OTHER',
    'lowercase CBC' => 'cbc',
    'lowercase diabetes' => 'diabetes',
    'lowercase liver function' => 'liver_function',
    'hyphenated liver function' => 'LIVER-FUNCTION',
    'null' => null,
]);
test('category input follows the existing whitespace trimming policy', function () {
    $user = User::factory()->create();

    $this->withToken(phaseTwoToken($user))
        ->postJson('/api/v1/reports', [
            'test_category' => ' CBC ',
            'source_type' => ReportSourceType::Image->value,
        ])
        ->assertCreated()
        ->assertJsonPath('data.report.test_category', ReportTestCategory::Cbc->value);

    expect($user->reports()->sole()->test_category)->toBe(ReportTestCategory::Cbc);
});
test('each supported report category accepts a compatible file', function (ReportTestCategory $category, ReportSourceType $sourceType) {
    $user = User::factory()->create();
    $report = Report::factory()
        ->for($user)
        ->forCategory($category)
        ->create(['source_type' => $sourceType]);
    $filename = $sourceType === ReportSourceType::Pdf ? 'report.pdf' : 'report.png';
    $file = $sourceType === ReportSourceType::Pdf
        ? UploadedFile::fake()->createWithContent($filename, "%PDF-1.4\n%%EOF")
        : phaseTwoPngUpload($filename);

    $response = $this->withToken(phaseTwoToken($user))
        ->post('/api/v1/reports/'.$report->getKey().'/files', [
            'file' => $file,
        ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.file.original_name', $filename)
        ->assertJsonMissingPath('data.file.storage_path');

    $reportFile = $report->file()->firstOrFail();
    Storage::disk('local')->assertExists($reportFile->storage_path);
    expect($reportFile->storage_path)->toStartWith('reports/'.$user->getKey().'/'.$report->getKey().'/');
})->with([
    'CBC image' => [ReportTestCategory::Cbc, ReportSourceType::Image],
    'diabetes image' => [ReportTestCategory::Diabetes, ReportSourceType::Image],
    'liver function PDF' => [ReportTestCategory::LiverFunction, ReportSourceType::Pdf],
]);
test('unsupported report files are rejected', function () {
    $user = User::factory()->create();
    $report = Report::factory()->for($user)->create();

    $this->withToken(phaseTwoToken($user))
        ->post('/api/v1/reports/'.$report->getKey().'/files', [
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'not a report'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'REPORT_FILE_INVALID');
});

test('oversized report files are rejected with a stable code', function () {
    config()->set('ocr.max_upload_kilobytes', 1);
    $user = User::factory()->create();
    $report = Report::factory()->for($user)->create();

    $this->withToken(phaseTwoToken($user))
        ->post('/api/v1/reports/'.$report->getKey().'/files', [
            'file' => UploadedFile::fake()->create('report.png', 2, 'image/png'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(413)
        ->assertJsonPath('error_code', 'REPORT_FILE_TOO_LARGE');
});

test('users cannot upload to another users report', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    [$report] = phaseTwoReportWithFile($owner);

    $this->withToken(phaseTwoToken($otherUser))
        ->post('/api/v1/reports/'.$report->getKey().'/files', [
            'file' => phaseTwoPngUpload(),
        ], ['Accept' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'FORBIDDEN');
});

test('users cannot process another users report', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    [$report] = phaseTwoReportWithFile($owner);

    $this->withToken(phaseTwoToken($otherUser))
        ->postJson('/api/v1/reports/'.$report->getKey().'/process')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'FORBIDDEN');
});

test('processing requires a report file', function () {
    $user = User::factory()->create();
    $report = Report::factory()->for($user)->create();

    $this->withToken(phaseTwoToken($user))
        ->postJson('/api/v1/reports/'.$report->getKey().'/process')
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'REPORT_FILE_REQUIRED');

    Queue::assertNothingPushed();
});

test('each supported report category can queue OCR once and prevents duplicates', function (ReportTestCategory $category, ReportSourceType $sourceType) {
    $user = User::factory()->create();
    [$report] = phaseTwoReportWithFile($user, $category, $sourceType);
    $token = phaseTwoToken($user);

    $this->withToken($token)
        ->postJson('/api/v1/reports/'.$report->getKey().'/process')
        ->assertAccepted()
        ->assertJsonPath('data.job.status', 'QUEUED');

    $this->withToken($token)
        ->postJson('/api/v1/reports/'.$report->getKey().'/process')
        ->assertConflict()
        ->assertJsonPath('error_code', 'OCR_JOB_ALREADY_RUNNING');

    Queue::assertPushedOnce(ProcessReportOcr::class);
    expect($report->refresh()->status)->toBe(ReportStatus::Queued)
        ->and($report->test_category)->toBe($category)
        ->and($report->extractionJobs()->count())->toBe(1);
})->with([
    'CBC image' => [ReportTestCategory::Cbc, ReportSourceType::Image],
    'diabetes image' => [ReportTestCategory::Diabetes, ReportSourceType::Image],
    'liver function PDF' => [ReportTestCategory::LiverFunction, ReportSourceType::Pdf],
]);
test('a report owner can poll job status', function () {
    $owner = User::factory()->create();
    [$report, $reportFile] = phaseTwoReportWithFile($owner);
    $job = ExtractionJob::factory()->for($report)->for($reportFile, 'reportFile')->create([
        'status' => ExtractionJobStatus::Processing,
        'progress' => 55,
        'current_step' => 'OCR_EXTRACTION',
    ]);

    $this->withToken(phaseTwoToken($owner))
        ->getJson('/api/v1/jobs/'.$job->getKey())
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'PROCESSING')
        ->assertJsonPath('data.progress', 55);
});

test('users cannot poll another users job', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    [$report, $reportFile] = phaseTwoReportWithFile($owner);
    $job = ExtractionJob::factory()->for($report)->for($reportFile, 'reportFile')->create();

    $this->withToken(phaseTwoToken($otherUser))
        ->getJson('/api/v1/jobs/'.$job->getKey())
        ->assertForbidden()
        ->assertJsonPath('error_code', 'FORBIDDEN');
});

test('extracted results return raw rows without private paths', function () {
    $user = User::factory()->create();
    [$report, $reportFile] = phaseTwoReportWithFile($user);
    $report->update([
        'status' => ReportStatus::NeedsReview,
        'patient_age_years' => 24.5,
        'patient_sex' => PatientSex::Female,
    ]);
    $job = ExtractionJob::factory()->for($report)->for($reportFile, 'reportFile')->create([
        'status' => ExtractionJobStatus::Succeeded,
        'progress' => 100,
    ]);
    ExtractedResult::factory()->for($job, 'extractionJob')->for($report)->create([
        'raw_label' => 'Hgb',
        'raw_value' => '10,2',
        'confidence' => 0.91,
    ]);

    $response = $this->withToken(phaseTwoToken($user))
        ->getJson('/api/v1/reports/'.$report->getKey().'/extracted-results');

    $response->assertSuccessful()
        ->assertJsonPath('data.report_status', 'NEEDS_REVIEW')
        ->assertJsonPath('data.extraction_job_id', $job->getKey())
        ->assertJsonPath('data.patient_age_years', 24.5)
        ->assertJsonPath('data.patient_sex', PatientSex::Female->value)
        ->assertJsonPath('data.results.0.raw_label', 'Hgb')
        ->assertJsonPath('data.results.0.confidence', 0.91);

    expect($response->getContent())->not->toContain('storage_path')
        ->not->toContain('reports/example');
});

test('users cannot fetch another users extracted results', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    [$report] = phaseTwoReportWithFile($owner);

    $this->withToken(phaseTwoToken($otherUser))
        ->getJson('/api/v1/reports/'.$report->getKey().'/extracted-results')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'FORBIDDEN');
});
test('all report OCR endpoints require authentication', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('error_code', 'UNAUTHENTICATED');
})->with([
    'create report' => ['POST', '/api/v1/reports'],
    'upload file' => ['POST', '/api/v1/reports/1/files'],
    'process report' => ['POST', '/api/v1/reports/1/process'],
    'job status' => ['GET', '/api/v1/jobs/1'],
    'extracted results' => ['GET', '/api/v1/reports/1/extracted-results'],
]);
