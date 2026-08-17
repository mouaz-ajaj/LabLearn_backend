<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin REST client for Gemini's generateContent endpoint, mirroring KbsClient's
 * mechanics exactly (base URL + timeout + bounded retry + status-code error mapping +
 * safe logging that never includes the API key or the raw prompt/response content).
 * No vendor SDK is used, consistent with how OCR/KBS were integrated.
 */
class GeminiClient
{
    public function isConfigured(): bool
    {
        $key = config('ai.gemini.api_key');

        return (bool) config('ai.gemini.enabled') && is_string($key) && $key !== '';
    }

    /**
     * @param  array<string, mixed>  $responseSchema
     * @return array<string, mixed> the decoded JSON object produced by the model
     */
    public function generate(string $systemInstruction, string $userContent, array $responseSchema): array
    {
        $model = (string) config('ai.gemini.model');
        $response = $this->send("/v1beta/models/{$model}:generateContent", [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $userContent]],
            ]],
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
                'temperature' => 0.2,
                'maxOutputTokens' => (int) config('ai.gemini.max_output_tokens'),
            ],
        ]);

        $payload = $response->json();
        $text = is_array($payload) ? data_get($payload, 'candidates.0.content.parts.0.text') : null;
        if (! is_string($text) || $text === '') {
            throw new GeminiException('AI_INVALID_RESPONSE', 'The AI service returned an unexpected response shape.', false, $response->status());
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new GeminiException('AI_INVALID_JSON', 'The AI service did not return valid JSON.', false, $response->status());
        }

        Log::info('Gemini contextualization response received.', [
            'model' => $model,
            'status_code' => $response->status(),
            'finish_reason' => data_get($payload, 'candidates.0.finishReason'),
        ]);

        return $decoded;
    }

    /** @param array<string, mixed> $body */
    private function send(string $endpoint, array $body): Response
    {
        $attempts = max(1, (int) config('ai.gemini.retry_attempts'));
        $lastException = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->request()->post($endpoint, $body);
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

        throw $lastException ?? new GeminiException('AI_SERVICE_UNAVAILABLE', 'The AI service is unavailable.', true);
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl((string) config('ai.gemini.base_url'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('ai.gemini.connect_timeout_seconds'))
            ->timeout((int) config('ai.gemini.timeout_seconds'));
        $key = config('ai.gemini.api_key');
        if (is_string($key) && $key !== '') {
            $request = $request->withHeaders(['x-goog-api-key' => $key]);
        }

        return $request;
    }

    private function connectionException(ConnectionException $exception): GeminiException
    {
        $timeout = Str::contains(Str::lower($exception->getMessage()), ['timed out', 'timeout', 'curl error 28']);

        return new GeminiException(
            $timeout ? 'AI_TIMEOUT' : 'AI_SERVICE_UNAVAILABLE',
            $timeout ? 'The AI service timed out.' : 'The AI service is unavailable.',
            true,
            previous: $exception,
        );
    }

    private function responseException(Response $response): GeminiException
    {
        $serviceCode = $response->json('error.status');
        $serviceCode = is_string($serviceCode) ? $serviceCode : null;
        // Google's own error message is not sensitive (no request content echoed back),
        // but the API key itself is never logged anywhere in this class.
        Log::warning('Gemini rejected a request.', [
            'status' => $response->status(),
            'error_status' => $serviceCode,
        ]);
        if ($response->status() === 401 || $response->status() === 403) {
            return new GeminiException('AI_AUTHENTICATION_FAILED', 'The AI service rejected its credentials.', false, $response->status(), $serviceCode);
        }
        if ($response->status() === 504) {
            return new GeminiException('AI_TIMEOUT', 'The AI service timed out.', true, $response->status(), $serviceCode);
        }
        if ($response->status() === 429 || $response->status() === 503 || $response->serverError()) {
            return new GeminiException('AI_SERVICE_UNAVAILABLE', 'The AI service is unavailable.', true, $response->status(), $serviceCode);
        }

        return new GeminiException($serviceCode ?? 'AI_REQUEST_REJECTED', 'The AI service rejected the request.', false, $response->status(), $serviceCode);
    }
}
