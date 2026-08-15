<?php

namespace App\Services\Kbs;

use App\Models\Analysis;

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
        foreach (['summary', 'disclaimer', 'category_validation', 'verified_result_set'] as $key) {
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
                || ! is_array($conclusion['title'] ?? null)
                || ! is_array($conclusion['summary'] ?? null)
                || ! is_array($conclusion['evidence'] ?? null)
                || ! is_array($conclusion['rule_codes'] ?? null)
                || array_diff($conclusion['rule_codes'], $traceCodes) !== []) {
                throw $this->invalid();
            }
        }

        return $payload;
    }

    private function invalid(): KbsException
    {
        return new KbsException('KBS_INVALID_RESPONSE', 'The analysis service returned an invalid response.', false);
    }
}
