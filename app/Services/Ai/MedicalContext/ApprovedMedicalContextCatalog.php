<?php

namespace App\Services\Ai\MedicalContext;

/**
 * Read-only reader of the reviewed, source-grounded medical context catalog
 * (backend/resources/medical_context/*.json) - one file per KBS report category
 * (CBC, DIABETES, LIVER_FUNCTION). This is reviewed structured data, not
 * something generated at runtime or by Gemini: every group is authored in
 * advance from reputable sources (see each group's `sources` array) and Gemini
 * only ever receives what this class resolves - it never invents medical
 * context of its own.
 *
 * Only `review_status: "APPROVED"` groups are ever loaded into memory - a
 * "DRAFT" or "DISABLED" group is structurally impossible to expose to Gemini,
 * since it never enters `$groupsByCode` in the first place.
 *
 * A group is keyed by a stable `context_group_code` and lists every KBS
 * `conclusion_code` it applies to. Several related conclusion codes (e.g. a
 * general anemia pattern plus a more specific microcytic/hypochromic pattern)
 * can share one group so the presentation layer can synthesize one coherent
 * explanation instead of one isolated paragraph per KBS conclusion.
 */
class ApprovedMedicalContextCatalog
{
    private const APPROVED_STATUS = 'APPROVED';

    /** @var array<string, array<string, mixed>> context_group_code => group */
    private array $groupsByCode = [];

    /** @var array<string, string[]> conclusion_code => [context_group_code, ...] */
    private array $groupCodesByConclusionCode = [];

    public function __construct(string $catalogDirectory)
    {
        foreach (glob(rtrim($catalogDirectory, '/').'/*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded) || ! is_array($decoded['groups'] ?? null)) {
                continue;
            }
            foreach ($decoded['groups'] as $group) {
                if (! is_array($group) || ($group['review_status'] ?? null) !== self::APPROVED_STATUS) {
                    continue;
                }
                $code = $group['context_group_code'] ?? null;
                if (! is_string($code) || $code === '') {
                    continue;
                }
                $this->groupsByCode[$code] = $group;
                foreach ($group['conclusion_codes'] ?? [] as $conclusionCode) {
                    if (is_string($conclusionCode) && $conclusionCode !== '') {
                        $this->groupCodesByConclusionCode[$conclusionCode][] = $code;
                    }
                }
            }
        }
    }

    /**
     * Resolves the approved, deduplicated, supersedes-filtered set of context
     * groups for a list of KBS conclusion_codes. Deterministic: the same input
     * always produces the same output (sorted by context_group_code), and this
     * performs no partial/fuzzy matching - a conclusion_code either has an
     * approved group or it doesn't.
     *
     * A group listing `superseded_by_group_codes` (e.g. a general
     * GENERAL_ANEMIA_CONTEXT superseded by a more specific
     * MICROCYTIC_HYPOCHROMIC_ANEMIA_CONTEXT) is excluded whenever any of the
     * groups it names are ALSO matched for this same set of conclusion codes -
     * this is what prevents presenting a generic essay alongside the more
     * specific one that already covers the same finding.
     *
     * @param  string[]  $conclusionCodes
     * @return array<int, array<string, mixed>>
     */
    public function groupsForConclusionCodes(array $conclusionCodes): array
    {
        $matchedCodes = [];
        foreach ($conclusionCodes as $conclusionCode) {
            foreach ($this->groupCodesByConclusionCode[$conclusionCode] ?? [] as $groupCode) {
                $matchedCodes[$groupCode] = true;
            }
        }
        $matchedCodes = array_keys($matchedCodes);

        $result = [];
        foreach ($matchedCodes as $groupCode) {
            $group = $this->groupsByCode[$groupCode];
            $supersededBy = $group['superseded_by_group_codes'] ?? [];
            if (is_array($supersededBy) && array_intersect($supersededBy, $matchedCodes) !== []) {
                continue;
            }
            $result[] = $group;
        }

        usort($result, static fn (array $a, array $b): int => $a['context_group_code'] <=> $b['context_group_code']);

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function group(string $contextGroupCode): ?array
    {
        return $this->groupsByCode[$contextGroupCode] ?? null;
    }

    /** @return string[] every approved context_group_code, for coverage reporting/tests */
    public function allGroupCodes(): array
    {
        return array_keys($this->groupsByCode);
    }

    /** @return string[] every conclusion_code with at least one approved group, for coverage reporting */
    public function coveredConclusionCodes(): array
    {
        return array_keys($this->groupCodesByConclusionCode);
    }

    /**
     * Localizes a list of resolved groups (as returned by groupsForConclusionCodes())
     * into the exact payload shape sent to Gemini: every causes/symptoms/next_steps/
     * red_flags/student_context item carries its stable code and only the requested
     * language's text - Gemini is never asked to pick between ar/en itself. Shared by
     * every feature that consumes this catalog (Phase 4E's ApprovedMedicalContext
     * Resolver, Phase 4C's ComparisonMedicalContextResolver) so the localization shape
     * can never drift between them.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    public function localizeGroups(array $groups, string $language): array
    {
        return array_map(fn (array $group): array => $this->localizeGroup($group, $language), $groups);
    }

    /** @param array<string, mixed> $group */
    private function localizeGroup(array $group, string $language): array
    {
        $studentContext = $group['student_context'] ?? null;

        return [
            'context_group_code' => $group['context_group_code'],
            'related_conclusion_codes' => $group['conclusion_codes'],
            'patient_friendly_meaning' => $this->text($group['patient_friendly_meaning'] ?? null, $language),
            'clinical_relevance' => $this->text($group['clinical_relevance'] ?? null, $language),
            'possible_causes' => $this->items($group['possible_causes'] ?? [], $language, withDescription: true),
            'possible_symptoms' => $this->items($group['common_symptoms'] ?? [], $language),
            'next_steps' => $this->items($group['general_next_steps'] ?? [], $language),
            'red_flags' => $this->items($group['red_flags'] ?? [], $language),
            'student_context' => $studentContext === null ? null : [
                'pathophysiology' => $this->text($studentContext['pathophysiology'] ?? null, $language),
                'differential_considerations' => $this->items($studentContext['differential_considerations'] ?? [], $language),
                'distinguishing_information' => $this->items($studentContext['distinguishing_information'] ?? [], $language),
                'learning_points' => $this->items($studentContext['learning_points'] ?? [], $language),
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function items(array $items, string $language, bool $withDescription = false): array
    {
        return array_map(function (array $item) use ($language, $withDescription): array {
            $result = [
                'code' => $item['code'],
                'text' => $this->text($item['text'] ?? $item['label'] ?? null, $language),
            ];
            if ($withDescription) {
                $result['description'] = $this->text($item['description'] ?? null, $language);
            }

            return $result;
        }, $items);
    }

    /** @param array{en?: string, ar?: string}|null $localizedText */
    private function text(?array $localizedText, string $language): string
    {
        if ($localizedText === null) {
            return '';
        }
        $value = $language === 'ar' ? ($localizedText['ar'] ?? null) : ($localizedText['en'] ?? null);

        return is_string($value) && $value !== '' ? $value : (string) ($localizedText['en'] ?? '');
    }
}
