<?php

namespace App\Services\Quiz\GeneralQuestions\Kbs;

use App\Enums\ReportTestCategory;
use RuntimeException;

/**
 * Reads the KBS's own structured knowledge files directly from disk — the same files
 * kbs/core/loader.py reads, but never over HTTP and never through the live KBS
 * service (unlike Case-Specific generation). This is the single place the General
 * Question generator touches raw KBS JSON; every template family works only through
 * the typed accessors here.
 *
 * category_validation.py's CATEGORY_TO_PANEL mapping (CBC->cbc, DIABETES->glucose,
 * LIVER_FUNCTION->liver) is duplicated here deliberately — it is a small, stable
 * constant, and importing Python is not an option from PHP.
 */
final class KbsKnowledgeBase
{
    private const CATEGORY_TO_PANEL = [
        'CBC' => 'cbc',
        'DIABETES' => 'glucose',
        'LIVER_FUNCTION' => 'liver',
    ];

    /** @var array<string, KbsAnalyte> analyte_id => KbsAnalyte, across all categories */
    private array $analytesById = [];

    /** @var array<string, list<string>> category value => ordered analyte ids from panels.json tests[] */
    private array $panelAnalyteIds = [];

    /** @var array<string, list<string>> category value => required analyte ids from panels.json required[] */
    private array $requiredAnalyteIds = [];

    /** @var array<string, list<KbsRule>> category value => active rules */
    private array $rulesByCategory = [];

    /** @var list<string> liver rule logic keys the catalog could not represent, with a reason (for diagnostics) */
    private array $skippedLiverRuleCodes = [];

    private function __construct(
        private readonly ConditionNameCatalog $conditionNames,
        private readonly ConditionPhraseRenderer $phraseRenderer,
    ) {}

    public static function load(string $knowledgeBasePath, LiverRuleTriggerCatalog $liverCatalog): self
    {
        $kb = new self(new ConditionNameCatalog, new ConditionPhraseRenderer);
        $kb->loadAnalytes($knowledgeBasePath);
        $kb->loadPanels($knowledgeBasePath);
        $kb->loadRegularRules($knowledgeBasePath);
        $kb->loadLiverRules($knowledgeBasePath, $liverCatalog);

        return $kb;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("KBS knowledge base file not found: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private static function categoryForPanel(string $panelKey): ?ReportTestCategory
    {
        return match ($panelKey) {
            'cbc' => ReportTestCategory::Cbc,
            'glucose' => ReportTestCategory::Diabetes,
            'liver' => ReportTestCategory::LiverFunction,
            default => null,
        };
    }

    private function loadAnalytes(string $path): void
    {
        $tests = self::readJson($path.'/tests.json');
        $liverTests = self::readJson($path.'/liver_tests.json');
        $disambiguation = self::readJson($path.'/analyte_disambiguation.json');

        $ambiguousAliases = [];
        foreach (($disambiguation['groups'] ?? []) as $group) {
            foreach (($group['base_aliases'] ?? []) as $alias) {
                $ambiguousAliases[mb_strtolower((string) $alias)] = true;
            }
        }

        foreach ([...$tests, ...$liverTests] as $id => $raw) {
            $category = self::categoryForPanel((string) ($raw['panel'] ?? ''));
            if ($category === null) {
                continue;
            }
            $name = (string) ($raw['name'] ?? $id);
            $shortName = (string) ($raw['short_name'] ?? '');
            $aliases = array_map(static fn ($a): string => (string) $a, $raw['aliases'] ?? []);
            $safeAliases = array_values(array_unique(array_filter(
                $aliases,
                static function (string $alias) use ($name, $shortName, $ambiguousAliases): bool {
                    if ($alias === $name || $alias === $shortName) {
                        return false;
                    }

                    return ! isset($ambiguousAliases[mb_strtolower($alias)]);
                },
            )));

            $this->analytesById[$id] = new KbsAnalyte(
                id: (string) $id,
                category: $category,
                panel: (string) ($raw['panel'] ?? ''),
                inOfficialPanel: false, // set in loadPanels()
                name: $name,
                nameAr: (string) ($raw['name_ar'] ?? $name),
                shortName: $shortName,
                safeAliases: $safeAliases,
                derived: (bool) ($raw['derived'] ?? false),
                formula: isset($raw['formula']) ? (string) $raw['formula'] : null,
                classifier: isset($raw['classifier']) ? (string) $raw['classifier'] : null,
            );
        }
    }

    private function loadPanels(string $path): void
    {
        $panels = self::readJson($path.'/panels.json');
        foreach (self::CATEGORY_TO_PANEL as $categoryValue => $panelKey) {
            $panel = $panels[$panelKey] ?? null;
            if ($panel === null) {
                continue;
            }
            $ids = array_map(static fn ($id): string => (string) $id, $panel['tests'] ?? []);
            $this->panelAnalyteIds[$categoryValue] = $ids;
            $this->requiredAnalyteIds[$categoryValue] = array_map(
                static fn ($id): string => (string) $id,
                $panel['required'] ?? [],
            );
            foreach ($ids as $id) {
                if (isset($this->analytesById[$id])) {
                    $analyte = $this->analytesById[$id];
                    $this->analytesById[$id] = new KbsAnalyte(
                        id: $analyte->id, category: $analyte->category, panel: $analyte->panel,
                        inOfficialPanel: true, name: $analyte->name, nameAr: $analyte->nameAr,
                        shortName: $analyte->shortName, safeAliases: $analyte->safeAliases,
                        derived: $analyte->derived, formula: $analyte->formula, classifier: $analyte->classifier,
                    );
                }
            }
        }
    }

    private function loadRegularRules(string $path): void
    {
        $rules = [...self::readJson($path.'/rules.json'), ...self::readJson($path.'/expanded_rules.json')];
        foreach ($rules as $raw) {
            if (! ($raw['active'] ?? false)) {
                continue;
            }
            $panelKey = (string) ($raw['panel'] ?? 'cbc');
            $category = self::categoryForPanel($panelKey);
            if ($category === null) {
                continue;
            }
            $joint = $this->jointTriggersFromWhen($raw['when'] ?? []);
            $conditionId = (string) ($raw['condition_id'] ?? '');
            $this->rulesByCategory[$category->value][] = new KbsRule(
                code: (string) $raw['id'],
                category: $category,
                conditionId: $conditionId,
                active: true,
                weight: (int) ($raw['weight'] ?? 0),
                jointTriggers: $joint,
                conditionPhraseEn: $this->phraseRenderer->renderEn($joint, $this->analytesById),
                conditionPhraseAr: $this->phraseRenderer->renderAr($joint, $this->analytesById),
                patternNameEn: $this->conditionNames->nameEn($conditionId),
                patternNameAr: $this->conditionNames->nameAr($conditionId),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $when
     * @return list<KbsRuleTrigger>
     */
    private function jointTriggersFromWhen(array $when): array
    {
        $triggers = [];
        foreach (($when['all'] ?? []) as $entry) {
            $triggers[] = $this->triggerFromEntry($entry);
        }

        $any = $when['any'] ?? [];
        $minMatched = $when['min_matched'] ?? null;
        // An "any" clause whose min_matched equals its own size requires every listed
        // condition — logically identical to "all" — so it is safe to treat as jointly
        // required. A genuine "any one of several" clause is left out entirely.
        if ($any !== [] && $minMatched !== null && (int) $minMatched === count($any)) {
            foreach ($any as $entry) {
                $triggers[] = $this->triggerFromEntry($entry);
            }
        }

        return $triggers;
    }

    /** @param array<string, mixed> $entry */
    private function triggerFromEntry(array $entry): KbsRuleTrigger
    {
        $analyteId = (string) ($entry['test'] ?? '');
        if (isset($entry['status_in']) && is_array($entry['status_in'])) {
            return new KbsRuleTrigger($analyteId, array_map(static fn ($s): string => (string) $s, $entry['status_in']));
        }

        return KbsRuleTrigger::single($analyteId, (string) ($entry['status'] ?? ''));
    }

    private function loadLiverRules(string $path, LiverRuleTriggerCatalog $catalog): void
    {
        $rules = [...self::readJson($path.'/liver_rules.json'), ...self::readJson($path.'/expanded_liver_rules.json')];
        foreach ($rules as $raw) {
            if (! ($raw['active'] ?? false)) {
                continue;
            }
            $logic = (string) ($raw['logic'] ?? '');
            if (! $catalog->isRepresentable($logic)) {
                $this->skippedLiverRuleCodes[] = (string) $raw['id'];

                continue;
            }
            $joint = $catalog->triggersFor($logic);
            $conditionId = (string) ($raw['condition_id'] ?? '');
            $this->rulesByCategory[ReportTestCategory::LiverFunction->value][] = new KbsRule(
                code: (string) $raw['id'],
                category: ReportTestCategory::LiverFunction,
                conditionId: $conditionId,
                active: true,
                weight: (int) ($raw['weight'] ?? 0),
                jointTriggers: $joint,
                conditionPhraseEn: $this->phraseRenderer->renderEn($joint, $this->analytesById),
                conditionPhraseAr: $this->phraseRenderer->renderAr($joint, $this->analytesById),
                patternNameEn: $this->conditionNames->nameEn($conditionId),
                patternNameAr: $this->conditionNames->nameAr($conditionId),
            );
        }
    }

    /** Analytes officially listed in panels.json's tests[] for this category, in that order. */
    public function panelAnalytes(ReportTestCategory $category): array
    {
        return array_values(array_filter(array_map(
            fn (string $id): ?KbsAnalyte => $this->analytesById[$id] ?? null,
            $this->panelAnalyteIds[$category->value] ?? [],
        )));
    }

    /** All analytes tagged with this category's panel, including ones panels.json does not officially list (broader pool for non-membership templates). */
    public function allAnalytes(ReportTestCategory $category): array
    {
        return array_values(array_filter(
            $this->analytesById,
            static fn (KbsAnalyte $analyte): bool => $analyte->category === $category,
        ));
    }

    /** @return array<string, KbsAnalyte> */
    public function analytesById(): array
    {
        return $this->analytesById;
    }

    public function analyte(string $id): ?KbsAnalyte
    {
        return $this->analytesById[$id] ?? null;
    }

    /** @return list<string> */
    public function requiredAnalyteIds(ReportTestCategory $category): array
    {
        return $this->requiredAnalyteIds[$category->value] ?? [];
    }

    /** @return list<KbsRule> */
    public function rules(ReportTestCategory $category): array
    {
        return $this->rulesByCategory[$category->value] ?? [];
    }

    /** Analyte ids among a rule's joint triggers whose OWN category differs from the rule's category. */
    public function crossPanelAnalyteIds(KbsRule $rule): array
    {
        return array_values(array_filter(
            $rule->jointAnalyteIds(),
            fn (string $id): bool => ($this->analytesById[$id]?->category) !== $rule->category
                && isset($this->analytesById[$id]),
        ));
    }

    /** @return list<string> liver rule codes skipped because their logic key has no safe trigger representation */
    public function skippedLiverRuleCodes(): array
    {
        return $this->skippedLiverRuleCodes;
    }
}
