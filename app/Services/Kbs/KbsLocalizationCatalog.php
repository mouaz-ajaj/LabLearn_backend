<?php

namespace App\Services\Kbs;

/**
 * Read-only, direct-from-disk reader of the KBS knowledge_base JSON files, built
 * specifically for the historical localization repair command
 * (kbs:repair-localized-analysis-content). Mirrors kbs/core/loader.py's own merge
 * precedence (base -> liver -> expanded conditions; base + expanded + liver +
 * expanded-liver rules) so a stable identifier (conclusion_code / rule_code /
 * analyte_id) resolves to exactly the same Arabic text the live KBS would produce
 * today. This class reads only `name_ar`/`explanation_ar` fields - it never reads
 * or exposes medical-logic fields (thresholds, `when`, `weight`, `active`), and
 * performs no matching by free text - every lookup is by exact stable identifier.
 */
class KbsLocalizationCatalog
{
    /** @var array<string, string> condition_id => name_ar */
    private array $conditionNamesAr = [];

    /** @var array<string, string> rule_code (the rule's own "id") => explanation_ar */
    private array $ruleExplanationsAr = [];

    /** @var array<string, string> analyte_id (test_id) => name_ar */
    private array $analyteNamesAr = [];

    public function __construct(string $knowledgeBasePath)
    {
        $conditions = [
            ...self::readJsonObject($knowledgeBasePath.'/conditions.json'),
            ...self::readJsonObject($knowledgeBasePath.'/liver_conditions.json'),
            ...self::readJsonObject($knowledgeBasePath.'/expanded_conditions.json'),
        ];
        foreach ($conditions as $conditionId => $condition) {
            $nameAr = is_array($condition) ? ($condition['name_ar'] ?? null) : null;
            if (is_string($nameAr) && $nameAr !== '') {
                $this->conditionNamesAr[(string) $conditionId] = $nameAr;
            }
        }

        foreach (['rules.json', 'expanded_rules.json', 'liver_rules.json', 'expanded_liver_rules.json'] as $file) {
            foreach (self::readJsonList($knowledgeBasePath.'/'.$file) as $rule) {
                $id = is_array($rule) ? ($rule['id'] ?? null) : null;
                $explanationAr = is_array($rule) ? ($rule['explanation_ar'] ?? null) : null;
                if (is_string($id) && $id !== '' && is_string($explanationAr) && $explanationAr !== '') {
                    $this->ruleExplanationsAr[$id] = $explanationAr;
                }
            }
        }

        $tests = [
            ...self::readJsonObject($knowledgeBasePath.'/tests.json'),
            ...self::readJsonObject($knowledgeBasePath.'/liver_tests.json'),
        ];
        foreach ($tests as $testId => $test) {
            $nameAr = is_array($test) ? ($test['name_ar'] ?? null) : null;
            if (is_string($nameAr) && $nameAr !== '') {
                $this->analyteNamesAr[(string) $testId] = $nameAr;
            }
        }
    }

    public function conditionNameAr(string $conclusionCode): ?string
    {
        return $this->conditionNamesAr[$conclusionCode] ?? null;
    }

    public function ruleExplanationAr(string $ruleCode): ?string
    {
        return $this->ruleExplanationsAr[$ruleCode] ?? null;
    }

    public function analyteNameAr(string $analyteId): ?string
    {
        return $this->analyteNamesAr[$analyteId] ?? null;
    }

    /**
     * Reconstructs the exact same why_ar join api_contract.py performs at
     * analysis time: only rule codes that currently have a genuine Arabic
     * explanation contribute; a rule code still missing one is silently omitted
     * (never English-substituted), matching the fixed report_builder.py
     * behavior. Returns null (not repairable) when none of the supplied rule
     * codes currently have an Arabic explanation.
     *
     * @param  string[]  $ruleCodes
     */
    public function reconstructedSummaryAr(array $ruleCodes): ?string
    {
        $parts = array_values(array_filter(array_map(
            fn (string $ruleCode): ?string => $this->ruleExplanationAr($ruleCode),
            $ruleCodes,
        )));

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** @return array<string, mixed> */
    private static function readJsonObject(string $path): array
    {
        $decoded = self::readJsonRaw($path);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<mixed> */
    private static function readJsonList(string $path): array
    {
        $decoded = self::readJsonRaw($path);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function readJsonRaw(string $path): mixed
    {
        if (! is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true);
    }
}
