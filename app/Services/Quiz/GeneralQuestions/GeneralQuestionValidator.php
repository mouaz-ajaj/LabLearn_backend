<?php

namespace App\Services\Quiz\GeneralQuestions;

use App\Enums\ReportTestCategory;

/**
 * Validates one candidate question in isolation (Step 10's per-question checks). Used
 * two ways by GeneralQuestionGenerator: to silently drop the rare individually
 * malformed candidate (e.g. an accidental duplicate option label a template family
 * could not itself rule out) before it ever reaches the bank, and — via
 * validateBank() — as a hard, bank-wide safety net that fails the whole refresh loudly
 * if something more systemic is wrong, per the Fail-Safe requirement.
 */
final class GeneralQuestionValidator
{
    /** @return list<string> validation error messages; empty means valid */
    public function validateQuestion(GeneratedGeneralQuestion $question): array
    {
        $errors = [];

        if (trim($question->questionText['en'] ?? '') === '') {
            $errors[] = 'question_text.en is empty';
        }
        if (trim($question->questionText['ar'] ?? '') === '') {
            $errors[] = 'question_text.ar is empty';
        }
        if (str_contains($question->questionText['en'] ?? '', '[DEV FIXTURE]') || str_contains($question->questionText['ar'] ?? '', 'بيانات تجريبية')) {
            $errors[] = 'question_text looks like DEV placeholder content';
        }

        if (count($question->options) !== 4) {
            $errors[] = 'must have exactly 4 options, got '.count($question->options);
        }
        $enTexts = [];
        $arTexts = [];
        foreach ($question->options as $id => $option) {
            if (trim($option['en'] ?? '') === '') {
                $errors[] = "option {$id}.en is empty";
            }
            if (trim($option['ar'] ?? '') === '') {
                $errors[] = "option {$id}.ar is empty";
            }
            $enTexts[] = mb_strtolower(trim($option['en'] ?? ''));
            $arTexts[] = trim($option['ar'] ?? '');
        }
        if (count(array_unique($enTexts)) !== count($enTexts)) {
            $errors[] = 'options are not unique in English';
        }
        if (count(array_unique($arTexts)) !== count($arTexts)) {
            $errors[] = 'options are not unique in Arabic';
        }

        if (! isset($question->options[$question->correctOptionId])) {
            $errors[] = "correct_option_id '{$question->correctOptionId}' does not reference an existing option";
        }

        if (trim($question->explanation['en'] ?? '') === '') {
            $errors[] = 'explanation.en is empty';
        }
        if (trim($question->explanation['ar'] ?? '') === '') {
            $errors[] = 'explanation.ar is empty';
        }
        if (mb_strlen(trim($question->explanation['en'] ?? '')) < 15) {
            $errors[] = 'explanation.en is too short to be a real KBS-grounded reason';
        }

        if ($question->stableSourceKey === '') {
            $errors[] = 'stable_source_key is empty';
        }
        if ($question->templateFamily === '') {
            $errors[] = 'template_family is empty';
        }
        if (! in_array($question->sourceType, ['ANALYTE', 'PANEL', 'RULE', 'DERIVED_VALUE', 'CLASSIFICATION', 'RELATIONSHIP'], true)) {
            $errors[] = "source_type '{$question->sourceType}' is not a recognized value";
        }

        return $errors;
    }

    /**
     * @param  array<string, list<GeneratedGeneralQuestion>>  $byCategory  ReportTestCategory value => questions
     * @return list<string> bank-wide validation errors; empty means the bank is safe to persist
     */
    public function validateBank(array $byCategory): array
    {
        $errors = [];
        $seenKeys = [];
        $seenTextKeys = [];

        foreach (ReportTestCategory::cases() as $category) {
            $questions = $byCategory[$category->value] ?? [];
            if (count($questions) < 14) {
                $errors[] = "{$category->value}: only ".count($questions).' questions generated, minimum coverage is 14';
            }
            foreach ($questions as $question) {
                if (isset($seenKeys[$question->stableSourceKey])) {
                    $errors[] = "duplicate stable_source_key: {$question->stableSourceKey}";
                }
                $seenKeys[$question->stableSourceKey] = true;

                $textKey = $question->normalizedTextKey();
                if (isset($seenTextKeys[$textKey])) {
                    $errors[] = "duplicate/near-duplicate question text within {$category->value}: \"{$question->questionText['en']}\"";
                }
                $seenTextKeys[$textKey] = true;
            }
        }

        return $errors;
    }
}
