<?php

namespace App\Services\Ai;

use App\Enums\AiTaskType;
use App\Models\AiExplanation;
use App\Models\Analysis;
use Illuminate\Database\QueryException;

/**
 * Thin, task-agnostic read/write wrapper around the `ai_explanations` cache table.
 * Never persists a transient fallback as though it were successful Gemini output -
 * only a strictly validated response is ever written (see GeminiResultExplainer),
 * so a cache miss always means "not yet successfully generated", never "Gemini failed
 * last time" - the next request is always free to try Gemini again.
 */
class AiExplanationCache
{
    public function find(Analysis $analysis, AiTaskType $taskType, string $language, string $role, string $promptVersion, string $schemaVersion): ?AiExplanation
    {
        return AiExplanation::query()
            ->where('analysis_id', $analysis->getKey())
            ->where('task_type', $taskType->value)
            ->where('language', $language)
            ->where('role', $role)
            ->where('prompt_version', $promptVersion)
            ->where('schema_version', $schemaVersion)
            ->first();
    }

    /**
     * Concurrency-safe write: the unique index (analysis_id, task_type, language,
     * role, prompt_version, schema_version) is the actual safety net. Two requests
     * racing to generate the same identity will both attempt an insert; the loser's
     * insert fails on the unique constraint, and it simply reads back whatever the
     * winner just wrote instead of erroring or creating a duplicate row.
     *
     * @param  array<string, mixed>  $content
     */
    public function store(
        Analysis $analysis,
        AiTaskType $taskType,
        string $language,
        string $role,
        string $promptVersion,
        string $schemaVersion,
        ?string $model,
        array $content,
    ): AiExplanation {
        $identity = [
            'analysis_id' => $analysis->getKey(),
            'task_type' => $taskType->value,
            'language' => $language,
            'role' => $role,
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
        ];

        try {
            return AiExplanation::query()->create([
                ...$identity,
                'model' => $model,
                'content_json' => $content,
                'status' => 'AVAILABLE',
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            return AiExplanation::query()->where($identity)->firstOrFail();
        }
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
