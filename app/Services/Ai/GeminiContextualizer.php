<?php

namespace App\Services\Ai;

use App\Contracts\AiContextualizer;
use App\Enums\AiContextStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates Gemini contextualization: builds the minimal role-aware payload, calls
 * Gemini, strictly validates the response, and falls back deterministically on any
 * failure. This method NEVER throws - AI failure (timeout, 5xx, invalid JSON, schema
 * violation, unsupported ids, disabled, missing key) always degrades to
 * AiContextStatus::Fallback rather than breaking the comparison request.
 */
class GeminiContextualizer implements AiContextualizer
{
    public function __construct(
        private readonly GeminiClient $client,
        private readonly GeminiPromptBuilder $promptBuilder,
        private readonly ComparisonContextBuilder $contextBuilder,
        private readonly ComparisonResponseValidator $validator,
        private readonly ComparisonFallbackFormatter $fallbackFormatter,
    ) {}

    public function contextualize(array $comparison, User $user, string $language): AiContextResult
    {
        if (! $this->client->isConfigured()) {
            Log::info('Gemini contextualization skipped; AI is disabled or unconfigured.');

            return $this->fallback($comparison, $user, $language);
        }

        $role = $user->role->value === 'student' ? 'student' : 'regular';

        try {
            $context = $this->contextBuilder->build($comparison, $user, $language);
            $raw = $this->client->generate(
                $this->promptBuilder->systemInstruction($language, $role),
                $this->promptBuilder->userContent($context),
                $this->promptBuilder->responseSchema($language, $comparison['category'], $role),
            );

            $validated = $this->validator->validate(
                $raw,
                $language,
                $comparison['category'],
                $role,
                $this->contextBuilder->allowedAnalyteIdsBySection($comparison),
                $this->contextBuilder->allowedPatternTransitions($comparison),
                $this->contextBuilder->allowedMedicalContextCodes($comparison),
            );

            if ($validated === null) {
                Log::warning('Gemini response failed strict validation; using deterministic fallback.');

                return $this->fallback($comparison, $user, $language);
            }

            return new AiContextResult(AiContextStatus::Available, $validated);
        } catch (Throwable $exception) {
            // GeminiClient already logs safe operational metadata (status, model,
            // duration) for its own failures; this catch only records the failure
            // category so comparison observability doesn't depend on AI succeeding.
            Log::warning('Gemini contextualization failed; using deterministic fallback.', [
                'exception' => $exception::class,
            ]);

            return $this->fallback($comparison, $user, $language);
        }
    }

    /** @param  array<string, mixed>  $comparison */
    private function fallback(array $comparison, User $user, string $language): AiContextResult
    {
        return new AiContextResult(
            AiContextStatus::Fallback,
            $this->fallbackFormatter->format($comparison, $user, $language),
        );
    }
}
