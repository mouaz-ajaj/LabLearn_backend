<?php

namespace App\Services\Ocr;

use App\Models\ReportFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OcrClient
{
    /** @return array<string, mixed> */
    public function analyze(ReportFile $reportFile, string $requestId): array
    {
        $stream = Storage::disk($reportFile->storage_disk)->readStream($reportFile->storage_path);

        if (! is_resource($stream)) {
            throw new OcrException(
                'OCR_PROCESSING_FAILED',
                'The stored report file could not be read.',
                false,
            );
        }

        try {
            $response = $this->request()
                ->withHeaders(['X-Request-ID' => $requestId])
                ->attach('file', $stream, $reportFile->original_name, ['Content-Type' => $reportFile->mime_type])
                ->post((string) config('ocr.analyze_endpoint'), [
                    'preprocessing_step' => (string) config('ocr.preprocessing_step'),
                    'continue_on_page_error' => 'true',
                    'include_diagnostics' => 'false',
                    'response_profile' => (string) config('ocr.response_profile'),
                ]);
        } catch (ConnectionException $exception) {
            throw $this->connectionException($exception);
        } finally {
            fclose($stream);
        }

        if (! $response->successful()) {
            throw $this->responseException($response);
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? null) !== true || ! $this->containsStructuredTests($payload)) {
            throw new OcrException(
                'OCR_INVALID_RESPONSE',
                'The OCR service returned an invalid response.',
                false,
                $response->status(),
                requestId: $response->header('X-Request-ID'),
            );
        }

        Log::info('OCR extraction response received.', [
            'report_file_id' => $reportFile->getKey(),
            'status_code' => $response->status(),
            'ocr_request_id' => $payload['request_id'] ?? $response->header('X-Request-ID'),
        ]);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        try {
            $response = $this->request()->get((string) config('ocr.health_endpoint'));
        } catch (ConnectionException $exception) {
            throw $this->connectionException($exception);
        }

        if (! $response->successful()) {
            throw $this->responseException($response);
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            throw new OcrException(
                'OCR_INVALID_RESPONSE',
                'The OCR health endpoint returned an invalid response.',
                false,
                $response->status(),
            );
        }

        return $payload;
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl((string) config('ocr.base_url'))
            ->acceptJson()
            ->connectTimeout((int) config('ocr.connect_timeout_seconds'))
            ->timeout((int) config('ocr.timeout_seconds'));

        if (! (bool) config('ocr.verify_tls')) {
            $request = $request->withoutVerifying();
        }

        $apiKey = config('ocr.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withHeaders(['X-Internal-OCR-Key' => $apiKey]);
        }

        return $request;
    }

    private function connectionException(ConnectionException $exception): OcrException
    {
        $isTimeout = Str::contains(Str::lower($exception->getMessage()), ['timed out', 'timeout', 'curl error 28']);

        return new OcrException(
            $isTimeout ? 'OCR_TIMEOUT' : 'OCR_SERVICE_UNAVAILABLE',
            $isTimeout ? 'The OCR service timed out.' : 'The OCR service is unavailable.',
            true,
            previous: $exception,
        );
    }

    private function responseException(Response $response): OcrException
    {
        $serviceCode = $response->json('error.code');
        $serviceCode = is_string($serviceCode) ? $serviceCode : null;
        $requestIdValue = $response->json('request_id') ?: $response->header('X-Request-ID');
        $requestId = is_scalar($requestIdValue) ? (string) $requestIdValue : null;
        $isTimeout = $response->status() === 504 || $serviceCode === 'request_timeout';
        $isUnavailable = $response->status() === 429 || $response->status() === 503 || $response->serverError();

        if ($isTimeout) {
            return new OcrException('OCR_TIMEOUT', 'The OCR service timed out.', true, $response->status(), $serviceCode, $requestId);
        }

        if ($isUnavailable) {
            return new OcrException('OCR_SERVICE_UNAVAILABLE', 'The OCR service is unavailable.', true, $response->status(), $serviceCode, $requestId);
        }

        return new OcrException(
            'OCR_PROCESSING_FAILED',
            'The OCR service could not process this report.',
            false,
            $response->status(),
            $serviceCode,
            $requestId,
        );
    }

    /** @param array<string, mixed> $payload */
    private function containsStructuredTests(array $payload): bool
    {
        $tests = data_get($payload, 'data.tests', data_get($payload, 'report.tests'));

        return is_array($tests) && array_is_list($tests);
    }
}
