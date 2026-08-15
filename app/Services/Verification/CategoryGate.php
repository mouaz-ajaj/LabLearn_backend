<?php

namespace App\Services\Verification;

use App\Enums\ReportTestCategory;
use RuntimeException;

class CategoryGate
{
    /** @var array<string, array<string, string>>|null */
    private ?array $aliases = null;

    /** @param list<string> $labels
     * @return array{status: string, category: string|null, evidence: array<string, mixed>}
     */
    public function evaluate(ReportTestCategory $selectedCategory, array $labels): array
    {
        $selectedPanel = match ($selectedCategory) {
            ReportTestCategory::Cbc => 'cbc',
            ReportTestCategory::Diabetes => 'glucose',
            ReportTestCategory::LiverFunction => 'liver',
        };
        $matches = ['cbc' => [], 'glucose' => [], 'liver' => []];
        $unknownLabels = [];

        foreach ($labels as $label) {
            $normalized = $this->normalize($label);
            if ($normalized === '') {
                continue;
            }
            $matched = false;
            foreach ($this->aliases() as $panel => $panelAliases) {
                if (isset($panelAliases[$normalized])) {
                    $matches[$panel][] = $panelAliases[$normalized];
                    $matched = true;
                }
            }
            if (! $matched) {
                $unknownLabels[] = $label;
            }
        }

        foreach ($matches as $panel => $ids) {
            $matches[$panel] = array_values(array_unique($ids));
        }

        $selectedMatches = $matches[$selectedPanel];
        $otherMatches = array_merge(...array_values(array_filter(
            $matches,
            fn (string $panel): bool => $panel !== $selectedPanel,
            ARRAY_FILTER_USE_KEY,
        )));

        if ($selectedMatches === []) {
            $status = 'MISMATCH';
            $reason = $otherMatches === [] ? 'NO_SUPPORTED_ANALYTES' : 'OTHER_CATEGORY_ONLY';
        } elseif ($otherMatches !== []) {
            $status = 'AMBIGUOUS';
            $reason = 'MIXED_CATEGORY_EVIDENCE';
        } elseif (count($unknownLabels) > count($selectedMatches) * 2) {
            $status = 'AMBIGUOUS';
            $reason = 'UNSUPPORTED_ANALYTE_MAJORITY';
        } elseif (count($selectedMatches) === 1 && $this->isWeakSingleEvidence($selectedMatches[0])) {
            $status = 'AMBIGUOUS';
            $reason = 'WEAK_SINGLE_ANALYTE';
        } else {
            $status = 'MATCH';
            $reason = 'SUPPORTED_CATEGORY_EVIDENCE';
        }

        return [
            'status' => $status,
            'category' => $status === 'MATCH' ? $selectedCategory->value : null,
            'evidence' => [
                'selected_panel' => $selectedPanel,
                'matched_test_ids' => $matches,
                'unknown_labels' => array_values(array_unique($unknownLabels)),
                'reason' => $reason,
                'catalog_source' => 'kbs/knowledge_base/tests.json + liver_tests.json',
            ],
        ];
    }

    /** @return array<string, array<string, string>> */
    private function aliases(): array
    {
        if ($this->aliases !== null) {
            return $this->aliases;
        }

        $catalog = [];
        foreach (['tests.json', 'liver_tests.json'] as $filename) {
            $path = base_path('../kbs/knowledge_base/'.$filename);
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($decoded)) {
                throw new RuntimeException('KBS category catalog is unavailable.');
            }
            $catalog = [...$catalog, ...$decoded];
        }

        $aliases = ['cbc' => [], 'glucose' => [], 'liver' => []];
        foreach ($catalog as $id => $test) {
            if (! is_array($test) || ! is_string($id)) {
                continue;
            }
            $panel = $test['panel'] ?? $test['category'] ?? null;
            if (! is_string($panel) || ! isset($aliases[$panel])) {
                continue;
            }
            $names = [$id, $test['name'] ?? null, $test['name_en'] ?? null, $test['name_ar'] ?? null, $test['short_name'] ?? null];
            if (is_array($test['aliases'] ?? null)) {
                $names = [...$names, ...$test['aliases']];
            }
            foreach ($names as $name) {
                if (is_string($name) && ($normalized = $this->normalize($name)) !== '') {
                    $aliases[$panel][$normalized] = $id;
                }
            }
        }

        return $this->aliases = $aliases;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    private function isWeakSingleEvidence(string $testId): bool
    {
        return in_array($testId, ['hemoglobin', 'albumin', 'total_protein'], true);
    }
}
