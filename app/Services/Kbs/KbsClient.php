<?php

namespace App\Services\Kbs;

use App\Models\Analysis;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KbsClient
{
    public function __construct(private readonly KbsResponseValidator $validator) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function analyze(array $input, Analysis $analysis): array
    {
        $response = $this->send('post', (string) config('kbs.analyze_endpoint'), $input);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new KbsException('KBS_INVALID_RESPONSE', 'The analysis service returned an invalid response.', false, $response->status());
        }

        $validated = $this->validator->validate($payload, $analysis);
        Log::info('KBS analysis response received.', [
            'analysis_id' => $analysis->getKey(),
            'status_code' => $response->status(),
            'request_id' => $payload['request_id'] ?? null,
            'ruleset_version' => $payload['ruleset_version'] ?? null,
        ]);

        return $validated;
    }

    /**
     * Authoritative preflight validation: resolves analyte identity, checks required
     * inputs and unit acceptability, without executing any rule. Callers use this
     * before ever dispatching an analysis job, so a user never has to submit a queued
     * analysis just to discover an ambiguous label or an unsupported unit.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $response = $this->send('post', (string) config('kbs.validate_endpoint'), $input);
        $payload = $response->json();
        if (! is_array($payload)
            || ($payload['success'] ?? null) !== true
            || ! is_bool($payload['blocking'] ?? null)
            || ! is_bool($payload['ready_for_analysis'] ?? null)
            || ! is_array($payload['issues'] ?? null)
            || ! array_is_list($payload['issues'])) {
            throw new KbsException('KBS_INVALID_RESPONSE', 'The analysis service returned an invalid validation response.', false, $response->status());
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $response = $this->send('get', (string) config('kbs.metadata_endpoint'));
        $payload = $response->json();
        foreach (['input_schema_version', 'output_schema_version', 'engine_version', 'ruleset_version', 'analyte_catalog_version'] as $key) {
            if (! is_array($payload) || ! is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw new KbsException('KBS_INVALID_RESPONSE', 'The analysis service returned invalid metadata.', false, $response->status());
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $response = $this->send('get', (string) config('kbs.health_endpoint'), authenticate: false);
        $payload = $response->json();
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'ok' || ($payload['service'] ?? null) !== 'lablearn-kbs') {
            throw new KbsException('KBS_INVALID_RESPONSE', 'The analysis service health response was invalid.', false, $response->status());
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $endpoint, array $payload = [], bool $authenticate = true): Response
    {
        $attempts = max(1, (int) config('kbs.retry_attempts'));
        $lastException = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $request = $this->request($authenticate);
                $response = $method === 'post' ? $request->post($endpoint, $payload) : $request->get($endpoint);
                if ($response->successful()) {
                    return $response;
                }
                $exception = $this->responseException($response);
                if (! $exception->retryable || $attempt === $attempts) {
                    throw $exception;
                }
                $lastException = $exception;
            } catch (ConnectionException $exception) {
                $lastException = $this->connectionException($exception);
                if ($attempt === $attempts) {
                    throw $lastException;
                }
            }
        }

        throw $lastException ?? new KbsException('KBS_SERVICE_UNAVAILABLE', 'The analysis service is unavailable.', true);
    }

    private function request(bool $authenticate): PendingRequest
    {
        $request = Http::baseUrl((string) config('kbs.base_url'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('kbs.connect_timeout_seconds'))
            ->timeout((int) config('kbs.timeout_seconds'));
        if (! (bool) config('kbs.verify_tls')) {
            $request = $request->withoutVerifying();
        }
        $key = config('kbs.api_key');
        if ($authenticate && is_string($key) && $key !== '') {
            $request = $request->withHeaders(['X-Internal-KBS-Key' => $key]);
        }

        return $request;
    }

    private function connectionException(ConnectionException $exception): KbsException
    {
        $timeout = Str::contains(Str::lower($exception->getMessage()), ['timed out', 'timeout', 'curl error 28']);

        return new KbsException(
            $timeout ? 'KBS_TIMEOUT' : 'KBS_SERVICE_UNAVAILABLE',
            $timeout ? 'The analysis service timed out.' : 'The analysis service is unavailable.',
            true,
            previous: $exception,
        );
    }

    private function responseException(Response $response): KbsException
    {
        $serviceCode = $response->json('error.code');
        $serviceCode = is_string($serviceCode) ? $serviceCode : null;
        // The safe_error_message shown to API callers is intentionally generic (never leaks KBS
        // internals), so log the full structured error here — status, KBS error code/message,
        // and any validation "issues" detail — which is otherwise lost and undebuggable.
        Log::warning('KBS rejected a request.', [
            'status' => $response->status(),
            'error' => $response->json('error'),
        ]);
        if ($response->status() === 401 || $response->status() === 403) {
            return new KbsException('KBS_AUTHENTICATION_FAILED', 'The analysis service rejected its internal credentials.', false, $response->status(), $serviceCode);
        }
        if ($response->status() === 504) {
            return new KbsException('KBS_TIMEOUT', 'The analysis service timed out.', true, $response->status(), $serviceCode);
        }
        if ($response->status() === 429 || $response->status() === 503 || $response->serverError()) {
            return new KbsException('KBS_SERVICE_UNAVAILABLE', 'The analysis service is unavailable.', true, $response->status(), $serviceCode);
        }

        return new KbsException($serviceCode ?? 'KBS_ANALYSIS_REJECTED', 'The analysis service rejected the verified input.', false, $response->status(), $serviceCode);
    }
}
