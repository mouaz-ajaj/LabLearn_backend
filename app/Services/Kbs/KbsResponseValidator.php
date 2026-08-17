<?php

namespace App\Services\Kbs;

use App\Models\Analysis;
use Illuminate\Support\Facades\Log;

class KbsResponseValidator
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, Analysis $analysis): array
    {
        $requiredStrings = [
            'schema_version', 'input_schema_version', 'output_schema_version',
            'engine_version', 'ruleset_version', 'analyte_catalog_version',
            'request_id', 'category', 'status',
        ];
        foreach ($requiredStrings as $key) {
            if (! is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw $this->invalid();
            }
        }
        foreach (['normalized_results', 'facts', 'conclusions', 'rule_traces', 'missing_information', 'warnings'] as $key) {
            if (! is_array($payload[$key] ?? null) || ! array_is_list($payload[$key])) {
                throw $this->invalid();
            }
        }
        if (! $this->isWellFormedLocalizedText($payload['summary'] ?? null)
            || ! $this->isWellFormedLocalizedText($payload['disclaimer'] ?? null)) {
            throw $this->invalid();
        }
        $this->warnIfArabicLooksLikeEnglishFallback($payload['summary'], 'analysis summary');
        foreach ($payload['missing_information'] as $item) {
            if (! is_array($item) || ! $this->isWellFormedLocalizedText($item['message'] ?? null)) {
                throw $this->invalid();
            }
        }
        foreach (['category_validation', 'verified_result_set'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                throw $this->invalid();
            }
        }
        if (($payload['success'] ?? null) !== true
            || $payload['schema_version'] !== '1'
            || $payload['input_schema_version'] !== '1'
            || $payload['output_schema_version'] !== '1'
            || $payload['category'] !== $analysis->report_category
            || $payload['ruleset_version'] !== $analysis->ruleset_version
            || data_get($payload, 'verified_result_set.id') != $analysis->verified_result_set_id
            || (int) data_get($payload, 'verified_result_set.version') !== $analysis->verified_result_set_version
            || data_get($payload, 'category_validation.status') !== 'MATCH') {
            throw $this->invalid();
        }

        $traceCodes = [];
        foreach ($payload['rule_traces'] as $trace) {
            if (! is_array($trace)
                || ! is_string($trace['rule_code'] ?? null)
                || ! is_int($trace['rule_version'] ?? null)
                || ! is_bool($trace['fired'] ?? null)
                || ! is_array($trace['conditions'] ?? null)
                || ! is_array($trace['evidence'] ?? null)
                || ! is_array($trace['conclusion_codes'] ?? null)) {
                throw $this->invalid();
            }
            $traceCodes[] = $trace['rule_code'];
        }
        foreach ($payload['conclusions'] as $conclusion) {
            if (! is_array($conclusion)
                || ! is_string($conclusion['code'] ?? null)
                || ! is_string($conclusion['level'] ?? null)
                || ! $this->isWellFormedLocalizedText($conclusion['title'] ?? null)
                || ! $this->isWellFormedLocalizedText($conclusion['summary'] ?? null)
                || ! is_array($conclusion['evidence'] ?? null)
                || ! is_array($conclusion['rule_codes'] ?? null)
                || array_diff($conclusion['rule_codes'], $traceCodes) !== []) {
                throw $this->invalid();
            }
            $this->warnIfArabicLooksLikeEnglishFallback($conclusion['title'], "conclusion {$conclusion['code']} title");
            $this->warnIfArabicLooksLikeEnglishFallback($conclusion['summary'], "conclusion {$conclusion['code']} summary");
        }

        return $payload;
    }

    /**
     * Structural shape check for a LocalizedText object: `en` is required and must
     * be a non-empty string; `ar`, when present at all, must also be a non-empty
     * string (never an empty string, a number, or any other malformed shape).
     * This does not - and must not - inspect whether `ar` actually contains
     * Arabic-language content; that is Gemini/KBS-content correctness, not wire
     * contract correctness, and is out of scope for this boundary (see
     * LanguagePurityChecker for where content-language validation belongs, and
     * the KBS-side validate_active_localization() gate for where the underlying
     * translation completeness is actually enforced).
     */
    private function isWellFormedLocalizedText(mixed $localizedText): bool
    {
        if (! is_array($localizedText) || ! is_string($localizedText['en'] ?? null) || $localizedText['en'] === '') {
            return false;
        }
        if (array_key_exists('ar', $localizedText) && $localizedText['ar'] !== null) {
            return is_string($localizedText['ar']) && $localizedText['ar'] !== '';
        }

        return true;
    }

    /**
     * Purely structural, non-blocking signal: an `ar` value containing zero
     * Arabic-script characters is exactly the shape the historical KBS bug
     * produced (English text silently substituted into the Arabic slot - see
     * report_builder.py's why_ar fix). Deliberately checked as "contains no
     * Arabic script" rather than "equals `en` byte-for-byte" - the confirmed real
     * bug could assemble a *different* English sentence (joined rule
     * explanations) under `ar` while `en` held the condition's own English
     * description, so the two were never guaranteed to match exactly. This never
     * throws - a false positive here must not fail a real user's analysis - it
     * only logs, so a regression in a future KBS release is caught by monitoring
     * rather than silently reaching users again.
     *
     * @param  array{en?: string, ar?: string}  $localizedText
     */
    private function warnIfArabicLooksLikeEnglishFallback(array $localizedText, string $context): void
    {
        $ar = $localizedText['ar'] ?? null;
        if (is_string($ar) && $ar !== '' && preg_match('/[\x{0600}-\x{06FF}]/u', $ar) === 0) {
            Log::warning('KBS response: Arabic field contains no Arabic script - possible localization fallback regression.', [
                'context' => $context,
            ]);
        }
    }

    private function invalid(): KbsException
    {
        return new KbsException('KBS_INVALID_RESPONSE', 'The analysis service returned an invalid response.', false);
    }
}
