<?php

use App\Services\Ai\MedicalContext\ApprovedMedicalContextCatalog;
use Tests\TestCase;

/**
 * Scans the REAL, shipped catalog (resources/medical_context/*.json) for content
 * completeness, source provenance, and bilingual coverage. Unlike
 * ApprovedMedicalContextCatalogTest.php (which tests the reader class's logic
 * against disposable fixtures), this file is a content-quality gate on the actual
 * medical reference data reviewed for this project.
 */
// Needs the booted app for base_path()/app() - tests/Pest.php only auto-applies
// TestCase to Feature/, matching the established Unit-test-needs-app precedent
// (see ResultExplanationContextBuilderTest.php).
uses(TestCase::class);

function realCatalogFiles(): array
{
    $dir = base_path('resources/medical_context');
    $files = [];
    foreach (glob($dir.'/*.json') as $path) {
        $decoded = json_decode(file_get_contents($path), true);
        expect($decoded)->not->toBeNull("《{$path}》must be valid JSON");
        $files[basename($path)] = $decoded;
    }

    return $files;
}

function allRealGroups(): array
{
    $groups = [];
    foreach (realCatalogFiles() as $file => $decoded) {
        foreach ($decoded['groups'] as $group) {
            $groups[] = [$file, $group];
        }
    }

    return $groups;
}

test('every catalog file only contains APPROVED groups - no DRAFT/DISABLED content ships to production', function () {
    foreach (allRealGroups() as [$file, $group]) {
        expect($group['review_status'] ?? null)->toBe('APPROVED', "{$file}: {$group['context_group_code']} must be APPROVED to ship");
    }
});

test('every group has at least one source with organization, title, and an https url', function () {
    foreach (allRealGroups() as [$file, $group]) {
        $label = "{$file}: {$group['context_group_code']}";
        expect($group['sources'] ?? [])->not->toBeEmpty("{$label} must cite at least one source");
        foreach ($group['sources'] as $source) {
            expect($source['source_id'] ?? null)->toBeString()->not->toBeEmpty("{$label} source missing source_id")
                ->and($source['organization'] ?? null)->toBeString()->not->toBeEmpty("{$label} source missing organization")
                ->and($source['title'] ?? null)->toBeString()->not->toBeEmpty("{$label} source missing title")
                ->and($source['url'] ?? null)->toStartWith('https://', "{$label} source url must be https");
        }
    }
});

test('every group has non-empty Arabic and English text for every required bilingual field', function () {
    foreach (allRealGroups() as [$file, $group]) {
        $label = "{$file}: {$group['context_group_code']}";
        foreach (['patient_friendly_meaning', 'clinical_relevance'] as $field) {
            expect($group[$field]['en'] ?? '')->not->toBe('', "{$label}.{$field}.en must not be empty")
                ->and($group[$field]['ar'] ?? '')->not->toBe('', "{$label}.{$field}.ar must not be empty");
        }
        foreach (['possible_causes', 'common_symptoms', 'general_next_steps', 'red_flags'] as $listField) {
            foreach ($group[$listField] ?? [] as $item) {
                $textField = $item['label'] ?? $item['text'] ?? null;
                $key = isset($item['label']) ? 'label' : 'text';
                expect($item['code'] ?? null)->toBeString()->not->toBeEmpty("{$label}.{$listField} item missing code")
                    ->and($textField['en'] ?? '')->not->toBe('', "{$label}.{$listField}.{$key}.en must not be empty")
                    ->and($textField['ar'] ?? '')->not->toBe('', "{$label}.{$listField}.{$key}.ar must not be empty");
                if (isset($item['description'])) {
                    expect($item['description']['en'] ?? '')->not->toBe('', "{$label}.{$listField}.description.en must not be empty")
                        ->and($item['description']['ar'] ?? '')->not->toBe('', "{$label}.{$listField}.description.ar must not be empty");
                }
            }
        }
        if (($group['student_context'] ?? null) !== null) {
            $sc = $group['student_context'];
            expect($sc['pathophysiology']['en'] ?? '')->not->toBe('', "{$label}.student_context.pathophysiology.en must not be empty")
                ->and($sc['pathophysiology']['ar'] ?? '')->not->toBe('', "{$label}.student_context.pathophysiology.ar must not be empty");
            foreach (['differential_considerations', 'distinguishing_information', 'learning_points'] as $listField) {
                foreach ($sc[$listField] ?? [] as $item) {
                    expect($item['code'] ?? null)->toBeString()->not->toBeEmpty("{$label}.student_context.{$listField} item missing code")
                        ->and($item['text']['en'] ?? '')->not->toBe('', "{$label}.student_context.{$listField}.text.en must not be empty")
                        ->and($item['text']['ar'] ?? '')->not->toBe('', "{$label}.student_context.{$listField}.text.ar must not be empty");
                }
            }
        }
    }
});

test('every context code across the entire catalog is globally unique', function () {
    // Codes are the validator's allow-list unit - a code reused across two
    // different groups with different meanings would silently let Gemini
    // reference the wrong group's fact and still pass allow-listing.
    $seen = [];
    foreach (allRealGroups() as [$file, $group]) {
        $codes = [$group['context_group_code']];
        foreach (['possible_causes', 'common_symptoms', 'general_next_steps', 'red_flags'] as $listField) {
            foreach ($group[$listField] ?? [] as $item) {
                $codes[] = $item['code'];
            }
        }
        $sc = $group['student_context'] ?? null;
        if ($sc !== null) {
            foreach (['differential_considerations', 'distinguishing_information', 'learning_points'] as $listField) {
                foreach ($sc[$listField] ?? [] as $item) {
                    $codes[] = $item['code'];
                }
            }
        }
        foreach ($codes as $code) {
            $existing = $seen[$code] ?? '';
            expect($seen)->not->toHaveKey($code, "duplicate context code '{$code}' (also used in {$existing}), found again in {$file}");
            $seen[$code] = "{$file}:{$group['context_group_code']}";
        }
    }
});

test('every superseded_by_group_codes reference points at a real group in the same category file', function () {
    foreach (realCatalogFiles() as $file => $decoded) {
        $codesInFile = array_column($decoded['groups'], 'context_group_code');
        foreach ($decoded['groups'] as $group) {
            foreach ($group['superseded_by_group_codes'] ?? [] as $target) {
                $inFile = in_array($target, $codesInFile, true);
                expect($inFile)->toBeTrue("{$file}: {$group['context_group_code']} supersedes an unknown group '{$target}'");
            }
        }
    }
});

test('coverage report: active KBS conclusion codes vs. approved catalog coverage, per category', function () {
    // This mirrors kbs:repair-localized-analysis-content's own "never fabricate to
    // reach 100%" discipline (see backend/docs/localization-integrity-repair.md) -
    // this test documents the current gap, it does not require full coverage.
    $catalog = app(ApprovedMedicalContextCatalog::class);
    $covered = $catalog->coveredConclusionCodes();

    expect($covered)->not->toBeEmpty();
    // A sanity floor, not an exhaustive list - proves real, non-trivial coverage
    // exists for each of the three production categories without hard-coding the
    // full expected set (which would make this test as fragile as the catalog
    // content itself and defeat its purpose as a regression guard).
    foreach (['possible_anemia_pattern', 'microcytic_anemia_pattern', 'possible_thrombocytosis_pattern'] as $cbcCode) {
        expect($covered)->toContain($cbcCode);
    }
    foreach (['possible_diabetes_pattern', 'possible_hypoglycemia_pattern'] as $diabetesCode) {
        expect($covered)->toContain($diabetesCode);
    }
    foreach (['hepatocellular_injury_pattern', 'cholestatic_injury_pattern'] as $liverCode) {
        expect($covered)->toContain($liverCode);
    }
});
