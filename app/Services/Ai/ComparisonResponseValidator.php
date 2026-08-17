<?php

namespace App\Services\Ai;

/**
 * Never trusts Gemini output directly. Combines structural allow-listing (the
 * primary, reliable defense) with a limited keyword safety net
 * (MedicalSafetyPatterns, shared with Phase 4E) for the specific forbidden patterns
 * named in the product spec. Deliberately not presented as a comprehensive
 * medical-content validator - regex cannot verify medical correctness, only reject a
 * known-bad shape. See docs/phase-4c-comparison.md.
 *
 * 2026-08-17 redesign: the primary allow-listing check moved from "does this analyte_id
 * exist anywhere in the comparison" to "does this analyte_id exist in the EXACT SECTION
 * Gemini placed it in" - Laravel already decided section membership
 * (GroupAnalyteChanges), so Gemini structurally cannot move a MOVED_CLOSER_BUT_STILL_
 * ABNORMAL item into normalized_findings, because that analyte_id was never supplied
 * inside the normalized_findings allow-list to begin with. Pattern transitions are
 * checked the same way: the transition value Gemini echoes back must exactly match
 * what Laravel computed for that conclusion_code.
 *
 * Returns null on ANY violation - the caller must fall back to the deterministic
 * formatter rather than show partially-trusted content.
 */
class ComparisonResponseValidator
{
    private const SCHEMA_VERSION = '2';

    private const BASE_TOP_LEVEL_KEYS = [
        'schema_version', 'language', 'role', 'category', 'overall_picture',
        'normalized_findings', 'better_but_still_abnormal', 'new_or_worse_findings',
        'pattern_changes', 'interpretation', 'unchanged_summary', 'limitations',
    ];

    private const MAX_PROSE_LENGTH = 1500;

    private const MAX_ITEM_TEXT_LENGTH = 600;

    private const MAX_LIST_SIZE = 30;

    /**
     * @param  array<string, mixed>  $response  raw decoded Gemini JSON
     * @param  array{normalized: string[], better_but_still_abnormal: string[], new_or_worse: string[], persistent_abnormal: string[]}  $allowedAnalyteIdsBySection
     * @param  array<string, string>  $allowedPatternTransitions  conclusion_code => transition
     * @param  array{differential: string[], interpretation_clues: string[]}  $allowedMedicalContextCodes
     * @return array<string, mixed>|null the validated content, or null if invalid
     */
    public function validate(
        array $response,
        string $expectedLanguage,
        string $expectedCategory,
        string $expectedRole,
        array $allowedAnalyteIdsBySection,
        array $allowedPatternTransitions,
        array $allowedMedicalContextCodes,
    ): ?array {
        $expectedKeys = $expectedRole === 'student'
            ? [...self::BASE_TOP_LEVEL_KEYS, 'student_context']
            : self::BASE_TOP_LEVEL_KEYS;

        if (array_diff(array_keys($response), $expectedKeys) !== [] || array_diff($expectedKeys, array_keys($response)) !== []) {
            return null;
        }

        if (($response['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }
        if (($response['language'] ?? null) !== $expectedLanguage) {
            return null;
        }
        if (($response['role'] ?? null) !== $expectedRole) {
            return null;
        }
        if (($response['category'] ?? null) !== $expectedCategory) {
            return null;
        }

        $overallPicture = $response['overall_picture'] ?? null;
        if (! $this->isBoundedSafeString($overallPicture, self::MAX_PROSE_LENGTH, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $normalized = $this->validateCodedItems($response['normalized_findings'] ?? null, $allowedAnalyteIdsBySection['normalized'], 'analyte_id', $expectedLanguage);
        if ($normalized === null) {
            return null;
        }

        $betterButStillAbnormal = $this->validateCodedItems($response['better_but_still_abnormal'] ?? null, $allowedAnalyteIdsBySection['better_but_still_abnormal'], 'analyte_id', $expectedLanguage);
        if ($betterButStillAbnormal === null) {
            return null;
        }

        $newOrWorse = $this->validateCodedItems($response['new_or_worse_findings'] ?? null, $allowedAnalyteIdsBySection['new_or_worse'], 'analyte_id', $expectedLanguage);
        if ($newOrWorse === null) {
            return null;
        }

        $patternChanges = $this->validatePatternChanges($response['pattern_changes'] ?? null, $allowedPatternTransitions, $expectedLanguage);
        if ($patternChanges === null) {
            return null;
        }

        $interpretation = $response['interpretation'] ?? null;
        if (! $this->isBoundedSafeString($interpretation, self::MAX_PROSE_LENGTH, allowEmpty: true, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $unchangedSummary = $response['unchanged_summary'] ?? null;
        if (! $this->isBoundedSafeString($unchangedSummary, self::MAX_PROSE_LENGTH, allowEmpty: true, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $limitations = $response['limitations'] ?? null;
        if (! $this->isBoundedSafeString($limitations, self::MAX_PROSE_LENGTH, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $validated = [
            'schema_version' => self::SCHEMA_VERSION,
            'language' => $expectedLanguage,
            'role' => $expectedRole,
            'category' => $expectedCategory,
            'overall_picture' => $overallPicture,
            'normalized_findings' => $normalized,
            'better_but_still_abnormal' => $betterButStillAbnormal,
            'new_or_worse_findings' => $newOrWorse,
            'pattern_changes' => $patternChanges,
            'interpretation' => $interpretation,
            'unchanged_summary' => $unchangedSummary,
            'limitations' => $limitations,
        ];

        if ($expectedRole === 'student') {
            $studentContext = $this->validateStudentContext(
                $response['student_context'] ?? null,
                $allowedAnalyteIdsBySection['persistent_abnormal'],
                $allowedMedicalContextCodes,
                $expectedLanguage,
            );
            if ($studentContext === null) {
                return null;
            }
            $validated['student_context'] = $studentContext;
        }

        return $validated;
    }

    /**
     * @param  string[]  $allowedIds
     * @return array<int, array<string, string>>|null
     */
    private function validateCodedItems(mixed $items, array $allowedIds, string $idKey, string $expectedLanguage): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::MAX_LIST_SIZE) {
            return null;
        }

        $result = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), [$idKey, 'text']) !== []) {
                return null;
            }
            $id = $item[$idKey] ?? null;
            if (! is_string($id) || ! in_array($id, $allowedIds, true) || isset($seen[$id])) {
                return null;
            }
            if (! $this->isBoundedSafeString($item['text'] ?? null, self::MAX_ITEM_TEXT_LENGTH, expectedLanguage: $expectedLanguage)) {
                return null;
            }
            $seen[$id] = true;
            $result[] = [$idKey => $id, 'text' => $item['text']];
        }

        return $result;
    }

    /** @param  array<string, string>  $allowedPatternTransitions  conclusion_code => transition
     * @return array<int, array<string, string>>|null
     */
    private function validatePatternChanges(mixed $items, array $allowedPatternTransitions, string $expectedLanguage): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::MAX_LIST_SIZE) {
            return null;
        }

        $result = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['conclusion_code', 'transition', 'text']) !== []) {
                return null;
            }
            $code = $item['conclusion_code'] ?? null;
            if (! is_string($code) || ! array_key_exists($code, $allowedPatternTransitions) || isset($seen[$code])) {
                return null;
            }
            // The transition Gemini echoes back must exactly match what Laravel
            // computed - Gemini cannot invent or alter an APPEARED/DISAPPEARED/
            // PERSISTED/TRANSIENT classification.
            if (($item['transition'] ?? null) !== $allowedPatternTransitions[$code]) {
                return null;
            }
            if (! $this->isBoundedSafeString($item['text'] ?? null, self::MAX_ITEM_TEXT_LENGTH, expectedLanguage: $expectedLanguage)) {
                return null;
            }
            $seen[$code] = true;
            $result[] = ['conclusion_code' => $code, 'transition' => $item['transition'], 'text' => $item['text']];
        }

        return $result;
    }

    /**
     * @param  string[]  $allowedPersistentAbnormalIds
     * @param  array{differential: string[], interpretation_clues: string[]}  $allowedMedicalContextCodes
     * @return array<string, mixed>|null
     */
    private function validateStudentContext(
        mixed $studentContext,
        array $allowedPersistentAbnormalIds,
        array $allowedMedicalContextCodes,
        string $expectedLanguage,
    ): ?array {
        if (! is_array($studentContext) || array_diff(array_keys($studentContext), ['clinical_significance', 'differential_context', 'interpretation_clues', 'persistent_abnormalities']) !== []) {
            return null;
        }

        $clinicalSignificance = $studentContext['clinical_significance'] ?? null;
        if (! $this->isBoundedSafeString($clinicalSignificance, self::MAX_PROSE_LENGTH, allowEmpty: true, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $differential = $this->validateCodedItems($studentContext['differential_context'] ?? null, $allowedMedicalContextCodes['differential'], 'context_code', $expectedLanguage);
        if ($differential === null) {
            return null;
        }

        $interpretationClues = $this->validateCodedItems($studentContext['interpretation_clues'] ?? null, $allowedMedicalContextCodes['interpretation_clues'], 'context_code', $expectedLanguage);
        if ($interpretationClues === null) {
            return null;
        }

        $persistentAbnormalities = $this->validateCodedItems($studentContext['persistent_abnormalities'] ?? null, $allowedPersistentAbnormalIds, 'analyte_id', $expectedLanguage);
        if ($persistentAbnormalities === null) {
            return null;
        }

        return [
            'clinical_significance' => $clinicalSignificance,
            'differential_context' => $differential,
            'interpretation_clues' => $interpretationClues,
            'persistent_abnormalities' => $persistentAbnormalities,
        ];
    }

    /** See ResultExplanationResponseValidator::isBoundedSafeString() for why the
     * language-purity check only applies when `$expectedLanguage === 'ar'`. */
    private function isBoundedSafeString(mixed $value, int $maxLength, bool $allowEmpty = false, ?string $expectedLanguage = null): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $trimmed = trim($value);
        if ($trimmed === '' && ! $allowEmpty) {
            return false;
        }
        if (mb_strlen($value) > $maxLength) {
            return false;
        }
        if ($trimmed !== '' && $expectedLanguage === 'ar' && ! LanguagePurityChecker::hasSufficientArabicProse($value)) {
            return false;
        }

        return MedicalSafetyPatterns::isSafe($value);
    }
}
