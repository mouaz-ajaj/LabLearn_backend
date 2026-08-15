<?php

use App\Models\ReportFile;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('reports/test/report.png', 'report bytes');
    Http::preventStrayRequests();
    config()->set('ocr.base_url', 'http://127.0.0.1:9001');
});

function phaseTwoClientFile(): ReportFile
{
    return new ReportFile([
        'original_name' => 'report.png',
        'storage_disk' => 'local',
        'storage_path' => 'reports/test/report.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 12,
        'checksum' => hash('sha256', 'report bytes'),
    ]);
}

function phaseTwoClientResponse(): array
{
    return [
        'success' => true,
        'request_id' => 'ocr-client-test',
        'api_version' => '1.0.0',
        'response_contract_version' => '2.0',
        'data' => ['tests' => []],
        'error' => null,
    ];
}

test('the OCR client sends a private multipart request with configured metadata', function () {
    config()->set('ocr.api_key', 'internal-secret');
    Http::fake(['*' => Http::response(phaseTwoClientResponse())]);

    $response = app(OcrClient::class)->analyze(phaseTwoClientFile(), 'lablearn-test-request');

    expect($response['request_id'])->toBe('ocr-client-test');
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://127.0.0.1:9001/api/v1/ocr/analyze'
            && $request->method() === 'POST'
            && $request->isMultipart()
            && $request->hasHeader('X-Internal-OCR-Key', 'internal-secret')
            && $request->hasHeader('X-Request-ID', 'lablearn-test-request')
            && collect($request->data())->doesntContain(
                fn (array $part): bool => in_array(
                    $part['name'] ?? null,
                    ['test_category', 'patient_age_years', 'patient_sex'],
                    true,
                ),
            );
    });
});

test('OCR connection timeouts are mapped to a retryable stable error', function () {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    try {
        app(OcrClient::class)->analyze(phaseTwoClientFile(), 'timeout-test');
        $this->fail('Expected an OCR timeout exception.');
    } catch (OcrException $exception) {
        expect($exception->errorCode)->toBe('OCR_TIMEOUT')
            ->and($exception->safeMessage)->toBe('The OCR service timed out.')
            ->and($exception->retryable)->toBeTrue();
    }
});

test('OCR connection failures are mapped to service unavailable', function () {
    Http::fake(['*' => Http::failedConnection('Could not connect to host')]);

    try {
        app(OcrClient::class)->analyze(phaseTwoClientFile(), 'unavailable-test');
        $this->fail('Expected an OCR unavailable exception.');
    } catch (OcrException $exception) {
        expect($exception->errorCode)->toBe('OCR_SERVICE_UNAVAILABLE')
            ->and($exception->retryable)->toBeTrue();
    }
});

test('temporary OCR service responses are retryable without leaking details', function () {
    Http::fake(['*' => Http::response([
        'success' => false,
        'request_id' => 'ocr-busy',
        'error' => ['code' => 'service_busy', 'message' => 'worker internals'],
    ], 503)]);

    try {
        app(OcrClient::class)->analyze(phaseTwoClientFile(), 'busy-test');
        $this->fail('Expected an OCR unavailable exception.');
    } catch (OcrException $exception) {
        expect($exception->errorCode)->toBe('OCR_SERVICE_UNAVAILABLE')
            ->and($exception->safeMessage)->not->toContain('worker internals')
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->serviceErrorCode)->toBe('service_busy');
    }
});

test('successful malformed OCR JSON is rejected safely', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['unexpected' => []]])]);

    try {
        app(OcrClient::class)->analyze(phaseTwoClientFile(), 'invalid-response-test');
        $this->fail('Expected an invalid OCR response exception.');
    } catch (OcrException $exception) {
        expect($exception->errorCode)->toBe('OCR_INVALID_RESPONSE')
            ->and($exception->retryable)->toBeFalse();
    }
});

test('the OCR health command reports reachable service metadata', function () {
    Http::fake(['*' => Http::response([
        'status' => 'ok',
        'service' => 'medical-laboratory-ocr-api',
        'version' => '1.0.0',
    ])]);

    $this->artisan('ocr:health')
        ->expectsOutput('OCR service is reachable.')
        ->expectsOutput('URL: http://127.0.0.1:9001')
        ->expectsOutput('Status: ok')
        ->expectsOutput('Service: medical-laboratory-ocr-api')
        ->expectsOutput('Version: 1.0.0')
        ->assertSuccessful();
});

test('the OCR health command exits non-zero when service is unavailable', function () {
    Http::fake(['*' => Http::failedConnection('Could not connect to host')]);

    $this->artisan('ocr:health')
        ->expectsOutput('OCR service is not reachable.')
        ->expectsOutput('Error: OCR_SERVICE_UNAVAILABLE')
        ->assertFailed();
});
