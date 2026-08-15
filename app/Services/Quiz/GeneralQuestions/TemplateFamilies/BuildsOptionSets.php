<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

/**
 * Shared helper for assembling a 4-option MCQ from one correct {en,ar} pair and
 * exactly 3 distractor {en,ar} pairs. Option lettering (a=correct, b..d=distractors)
 * is arbitrary here — FinalizeQuizSession shuffles display order per quiz snapshot,
 * so the Question Bank's own stored order never reaches a student directly.
 */
trait BuildsOptionSets
{
    /**
     * @param  array{en: string, ar: string}  $correct
     * @param  list<array{en: string, ar: string}>  $distractors  exactly 3 entries
     * @return array<string, array{en: string, ar: string}>
     */
    private function buildOptions(array $correct, array $distractors): array
    {
        $options = ['a' => $correct];
        $letters = ['b', 'c', 'd'];
        foreach (array_values($distractors) as $index => $distractor) {
            $options[$letters[$index]] = $distractor;
        }

        return $options;
    }
}
