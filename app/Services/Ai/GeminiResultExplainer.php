<?php

namespace App\Services\Ai;

use App\Contracts\ResultExplainer;
use App\Enums\AiContextStatus;
use App\Enums\AiExplanationSource;
use App\Enums\AiTaskType;
use App\Models\Analysis;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates result explanation: checks the versioned cache first, and only on a
 * miss builds the minimal payload, calls Gemini, strictly validates the response, and
 * persists it before returning. Falls back deterministically on ANY failure (disabled,
 * missing key, timeout, 429/5xx, invalid JSON, schema violation, unsupported
 * identifiers) - this method NEVER throws and a fallback is NEVER persisted as though
 * it were successful Gemini output, so the next request always gets a fresh chance at
 * a real Gemini response. Mirrors GeminiContextualizer's (Phase 4C) exact resilience
 * discipline, adapted for the cache-first flow this task requires.
 */
class GeminiResultExplainer implements ResultExplainer
{
    private const TASK_TYPE = AiTaskType::ResultExplanation;

    // v2 (2026-08-17): role-aware, catalog-grounded causes/symptoms/next-steps/
    // red-flags/student-differential content model - see ResultExplanationPromptBuilder
    // and backend/docs/phase-4e-result-explanation.md.
    private const SCHEMA_VERSION = '2';

    public function __construct(
        private readonly GeminiClient $client,
        private readonly ResultExplanationPromptBuilder $promptBuilder,
        private readonly ResultExplanationContextBuilder $contextBuilder,
        private readonly ResultExplanationResponseValidator $validator,
        private readonly ResultExplanationFallbackFormatter $fallbackFormatter,
        private readonly AiExplanationCache $cache,
    ) {}

    public function explain(Analysis $analysis, string $role, string $language): ResultExplanationResult
    {
        $promptVersion = (string) config('ai.result_explanation.prompt_version');

        $cached = $this->cache->find($analysis, self::TASK_TYPE, $language, $role, $promptVersion, self::SCHEMA_VERSION);
        if ($cached !== null) {
            Log::info('Result explanation cache hit.', ['analysis_id' => $analysis->getKey(), 'language' => $language, 'role' => $role]);

            return new ResultExplanationResult(AiContextStatus::Available, AiExplanationSource::Cached, $cached->content_json);
        }

        if (! $this->client->isConfigured() || ! (bool) config('ai.result_explanation.enabled')) {
            Log::info('Result explanation skipped; AI is disabled or unconfigured.', ['analysis_id' => $analysis->getKey()]);

            return $this->fallback($analysis, $role, $language);
        }

        try {
            $context = $this->contextBuilder->build($analysis, $role, $language);
            $raw = $this->client->generate(
                $this->promptBuilder->systemInstruction($language, $role),
                $this->promptBuilder->userContent($context),
                $this->promptBuilder->responseSchema($language, $analysis->report_category, $role),
            );

            $validated = $this->validator->validate(
                $raw,
                $language,
                $analysis->report_category,
                $role,
                $this->contextBuilder->allowedConclusionCodes($analysis),
                $this->contextBuilder->allowedMedicalContextCodes($analysis),
            );

            if ($validated === null) {
                Log::warning('Result explanation response failed strict validation; using deterministic fallback.', ['analysis_id' => $analysis->getKey()]);

                return $this->fallback($analysis, $role, $language);
            }

            $model = (string) config('ai.gemini.model');
            $this->cache->store($analysis, self::TASK_TYPE, $language, $role, $promptVersion, self::SCHEMA_VERSION, $model, $validated);

            return new ResultExplanationResult(AiContextStatus::Available, AiExplanationSource::Generated, $validated);
        } catch (Throwable $exception) {
            // GeminiClient already logs safe operational metadata (status, model,
            // duration) for its own failures; this catch only records the failure
            // category so result observability doesn't depend on AI succeeding.
            Log::warning('Result explanation generation failed; using deterministic fallback.', [
                'analysis_id' => $analysis->getKey(),
                'exception' => $exception::class,
            ]);

            return $this->fallback($analysis, $role, $language);
        }
    }

    private function fallback(Analysis $analysis, string $role, string $language): ResultExplanationResult
    {
        return new ResultExplanationResult(
            AiContextStatus::Fallback,
            AiExplanationSource::Fallback,
            $this->fallbackFormatter->format($analysis, $role, $language),
        );
    }
}
