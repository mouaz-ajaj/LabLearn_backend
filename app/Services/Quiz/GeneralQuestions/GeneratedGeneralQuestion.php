<?php

namespace App\Services\Quiz\GeneralQuestions;

use App\Enums\ReportTestCategory;

/**
 * One candidate General question produced by a template family, before validation and
 * database persistence. Mirrors exactly the columns
 * database/migrations/2026_08_15_000001_add_kbs_traceability_columns_to_questions_table.php
 * added to `questions`.
 */
final class GeneratedGeneralQuestion
{
    /**
     * @param  array{en: string, ar: string}  $questionText
     * @param  array<string, array{en: string, ar: string}>  $options  exactly 4 entries, keyed 'a'..'d'
     * @param  array{en: string, ar: string}  $explanation
     */
    public function __construct(
        public readonly ReportTestCategory $category,
        public readonly array $questionText,
        public readonly array $options,
        public readonly string $correctOptionId,
        public readonly array $explanation,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $templateFamily,
        public readonly string $stableSourceKey,
        public readonly string $generatorVersion,
    ) {}

    /**
     * Normalized (question stem + correct answer) key, used for near-duplicate
     * detection. The stem alone is not enough: several families (Panel Membership,
     * Required Inputs, Category Comparison, ...) deliberately reuse one identical stem
     * per category and vary only the options/correct answer — including the correct
     * answer text is what keeps those legitimately distinct questions from collapsing
     * into a single "duplicate", while two questions that really do ask the same thing
     * with the same answer still collide as intended.
     */
    public function normalizedTextKey(): string
    {
        $stem = $this->normalize($this->questionText['en']);
        $answer = $this->normalize($this->options[$this->correctOptionId]['en'] ?? '');

        return $this->category->value.'|'.$stem.'|'.$answer;
    }

    private function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text));

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized);
    }
}
