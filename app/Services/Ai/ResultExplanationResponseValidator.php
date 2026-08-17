<?php

namespace App\Services\Ai;

/**
 * Never trusts Gemini output directly - mirrors ComparisonResponseValidator's
 * discipline exactly, adapted to the result-explanation schema. Structural
 * allow-listing (conclusion codes AND, since the 2026-08-17 content redesign, every
 * cause/symptom/next-step/red-flag/differential/distinguishing-information
 * context_code must already exist in what Laravel itself supplied) is the primary
 * defense - Gemini cannot invent a new finding, a new cause, or a new symptom.
 * MedicalSafetyPatterns and LanguagePurityChecker are narrow supplementary nets on
 * the free-text prose fields, not a claim of medical-correctness validation.
 *
 * Returns null on ANY violation - the caller must fall back to the deterministic
 * formatter rather than persist or show partially-trusted content.
 */
class ResultExplanationResponseValidator
{
    private const SCHEMA_VERSION = '2';

    private const BASE_TOP_LEVEL_KEYS = [
        'schema_version', 'language', 'category', 'role', 'overview', 'possible_causes',
        'possible_symptoms', 'clinical_relevance', 'next_steps', 'red_flags', 'limitations',
    ];

    private const MAX_OVERVIEW_LENGTH = 1500;

    private const MAX_PROSE_LENGTH = 1200;

    private const MAX_TITLE_LENGTH = 200;

    private const MAX_ITEM_TEXT_LENGTH = 600;

    private const MAX_LIST_SIZE = 12;

    /**
     * @param  array<string, mixed>  $response  raw decoded Gemini JSON
     * @param  string[]  $allowedConclusionCodes
     * @param  array{causes: string[], symptoms: string[], next_steps: string[], red_flags: string[], differential: string[], distinguishing: string[]}  $allowedContextCodes
     * @return array<string, mixed>|null the validated content, or null if invalid
     */
    public function validate(
        array $response,
        string $expectedLanguage,
        string $expectedCategory,
        string $expectedRole,
        array $allowedConclusionCodes,
        array $allowedContextCodes,
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
        if (($response['category'] ?? null) !== $expectedCategory) {
            return null;
        }
        if (($response['role'] ?? null) !== $expectedRole) {
            return null;
        }

        $overview = $response['overview'] ?? null;
        if (! $this->isBoundedSafeString($overview, self::MAX_OVERVIEW_LENGTH, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $possibleCauses = $this->validateCauses($response['possible_causes'] ?? null, $allowedContextCodes['causes'], $expectedLanguage);
        if ($possibleCauses === null) {
            return null;
        }

        $possibleSymptoms = $this->validateCodedItems($response['possible_symptoms'] ?? null, $allowedContextCodes['symptoms'], $expectedLanguage);
        if ($possibleSymptoms === null) {
            return null;
        }

        $clinicalRelevance = $response['clinical_relevance'] ?? null;
        if (! $this->isBoundedSafeString($clinicalRelevance, self::MAX_PROSE_LENGTH, allowEmpty: true, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $nextSteps = $this->validateCodedItems($response['next_steps'] ?? null, $allowedContextCodes['next_steps'], $expectedLanguage);
        if ($nextSteps === null) {
            return null;
        }

        $redFlags = $this->validateCodedItems($response['red_flags'] ?? null, $allowedContextCodes['red_flags'], $expectedLanguage);
        if ($redFlags === null) {
            return null;
        }

        $limitations = $response['limitations'] ?? null;
        if (! $this->isBoundedSafeString($limitations, self::MAX_PROSE_LENGTH, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $validated = [
            'schema_version' => self::SCHEMA_VERSION,
            'language' => $expectedLanguage,
            'category' => $expectedCategory,
            'role' => $expectedRole,
            'overview' => $overview,
            'possible_causes' => $possibleCauses,
            'possible_symptoms' => $possibleSymptoms,
            'clinical_relevance' => $clinicalRelevance,
            'next_steps' => $nextSteps,
            'red_flags' => $redFlags,
            'limitations' => $limitations,
        ];

        if ($expectedRole === 'student') {
            $studentContext = $this->validateStudentContext($response['student_context'] ?? null, $allowedContextCodes, $expectedLanguage);
            if ($studentContext === null) {
                return null;
            }
            $validated['student_context'] = $studentContext;
        }

        return $validated;
    }

    /**
     * @param  string[]  $allowedCauseCodes
     * @return array<int, array{context_code:string,title:string,explanation:string}>|null
     */
    private function validateCauses(mixed $items, array $allowedCauseCodes, string $expectedLanguage): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::MAX_LIST_SIZE) {
            return null;
        }

        $result = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['context_code', 'title', 'explanation']) !== []) {
                return null;
            }
            $code = $item['context_code'] ?? null;
            if (! is_string($code) || ! in_array($code, $allowedCauseCodes, true) || isset($seen[$code])) {
                return null;
            }
            if (
                ! $this->isBoundedSafeString($item['title'] ?? null, self::MAX_TITLE_LENGTH, expectedLanguage: $expectedLanguage)
                || ! $this->isBoundedSafeString($item['explanation'] ?? null, self::MAX_ITEM_TEXT_LENGTH, expectedLanguage: $expectedLanguage)
            ) {
                return null;
            }
            $seen[$code] = true;
            $result[] = ['context_code' => $code, 'title' => $item['title'], 'explanation' => $item['explanation']];
        }

        return $result;
    }

    /**
     * @param  string[]  $allowedCodes
     * @return array<int, array{context_code:string,text:string}>|null
     */
    private function validateCodedItems(mixed $items, array $allowedCodes, string $expectedLanguage): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::MAX_LIST_SIZE) {
            return null;
        }

        $result = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['context_code', 'text']) !== []) {
                return null;
            }
            $code = $item['context_code'] ?? null;
            if (! is_string($code) || ! in_array($code, $allowedCodes, true) || isset($seen[$code])) {
                return null;
            }
            if (! $this->isBoundedSafeString($item['text'] ?? null, self::MAX_ITEM_TEXT_LENGTH, expectedLanguage: $expectedLanguage)) {
                return null;
            }
            $seen[$code] = true;
            $result[] = ['context_code' => $code, 'text' => $item['text']];
        }

        return $result;
    }

    /**
     * @param  array{causes: string[], symptoms: string[], next_steps: string[], red_flags: string[], differential: string[], distinguishing: string[]}  $allowedContextCodes
     * @return array<string, mixed>|null
     */
    private function validateStudentContext(mixed $studentContext, array $allowedContextCodes, string $expectedLanguage): ?array
    {
        if (! is_array($studentContext) || array_diff(array_keys($studentContext), ['pathophysiology', 'differential_considerations', 'distinguishing_information', 'learning_takeaway']) !== []) {
            return null;
        }

        $pathophysiology = $studentContext['pathophysiology'] ?? null;
        if (! $this->isBoundedSafeString($pathophysiology, self::MAX_PROSE_LENGTH, allowEmpty: true, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        $differential = $this->validateCodedItems($studentContext['differential_considerations'] ?? null, $allowedContextCodes['differential'], $expectedLanguage);
        if ($differential === null) {
            return null;
        }

        $distinguishing = $this->validateCodedItems($studentContext['distinguishing_information'] ?? null, $allowedContextCodes['distinguishing'], $expectedLanguage);
        if ($distinguishing === null) {
            return null;
        }

        $learningTakeaway = $studentContext['learning_takeaway'] ?? null;
        if (! $this->isBoundedSafeString($learningTakeaway, self::MAX_PROSE_LENGTH, expectedLanguage: $expectedLanguage)) {
            return null;
        }

        return [
            'pathophysiology' => $pathophysiology,
            'differential_considerations' => $differential,
            'distinguishing_information' => $distinguishing,
            'learning_takeaway' => $learningTakeaway,
        ];
    }

    /**
     * `$expectedLanguage` triggers LanguagePurityChecker only for `ar` (see
     * LanguagePurityChecker's docblock for the exact algorithm and documented
     * false-positive limitations). English responses are not language-purity-checked.
     */
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
