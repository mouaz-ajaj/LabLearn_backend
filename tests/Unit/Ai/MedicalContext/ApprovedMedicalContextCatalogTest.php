<?php

use App\Services\Ai\MedicalContext\ApprovedMedicalContextCatalog;

/**
 * Isolated behavior tests using a temporary fixture catalog directory (never the
 * real resources/medical_context/*.json) so these assertions are about the class's
 * resolution logic, not about any particular medical content.
 */
function fixtureCatalogDir(array $files): string
{
    $dir = sys_get_temp_dir().'/lablearn-catalog-test-'.uniqid();
    mkdir($dir);
    foreach ($files as $name => $content) {
        file_put_contents($dir.'/'.$name, json_encode($content, JSON_UNESCAPED_UNICODE));
    }

    return $dir;
}

test('only APPROVED groups are ever loaded - DRAFT and DISABLED are structurally excluded', function () {
    $dir = fixtureCatalogDir([
        'test.json' => ['schema_version' => '1', 'category' => 'CBC', 'groups' => [
            ['context_group_code' => 'APPROVED_GROUP', 'conclusion_codes' => ['a'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
            ['context_group_code' => 'DRAFT_GROUP', 'conclusion_codes' => ['b'], 'review_status' => 'DRAFT', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
            ['context_group_code' => 'DISABLED_GROUP', 'conclusion_codes' => ['c'], 'review_status' => 'DISABLED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
        ]],
    ]);
    $catalog = new ApprovedMedicalContextCatalog($dir);

    expect($catalog->allGroupCodes())->toBe(['APPROVED_GROUP'])
        ->and($catalog->group('DRAFT_GROUP'))->toBeNull()
        ->and($catalog->group('DISABLED_GROUP'))->toBeNull()
        ->and($catalog->groupsForConclusionCodes(['b']))->toBe([])
        ->and($catalog->groupsForConclusionCodes(['c']))->toBe([]);
});

test('an unrelated conclusion code resolves no context at all', function () {
    $dir = fixtureCatalogDir([
        'test.json' => ['schema_version' => '1', 'category' => 'CBC', 'groups' => [
            ['context_group_code' => 'GROUP_A', 'conclusion_codes' => ['pattern_a'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
        ]],
    ]);
    $catalog = new ApprovedMedicalContextCatalog($dir);

    expect($catalog->groupsForConclusionCodes(['completely_unrelated_pattern']))->toBe([]);
});

test('context resolution is deterministic across repeated calls, including order', function () {
    $dir = fixtureCatalogDir([
        'test.json' => ['schema_version' => '1', 'category' => 'CBC', 'groups' => [
            ['context_group_code' => 'GROUP_B', 'conclusion_codes' => ['pattern_b'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
            ['context_group_code' => 'GROUP_A', 'conclusion_codes' => ['pattern_a'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
        ]],
    ]);
    $catalog = new ApprovedMedicalContextCatalog($dir);

    $first = array_column($catalog->groupsForConclusionCodes(['pattern_a', 'pattern_b']), 'context_group_code');
    $second = array_column($catalog->groupsForConclusionCodes(['pattern_a', 'pattern_b']), 'context_group_code');

    expect($first)->toBe(['GROUP_A', 'GROUP_B']) // sorted, not insertion order
        ->and($second)->toBe($first);
});

test('a generic group superseded by a more specific matched group is excluded, but stands alone otherwise', function () {
    $dir = fixtureCatalogDir([
        'test.json' => ['schema_version' => '1', 'category' => 'CBC', 'groups' => [
            ['context_group_code' => 'GENERIC', 'conclusion_codes' => ['general_pattern'], 'superseded_by_group_codes' => ['SPECIFIC'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
            ['context_group_code' => 'SPECIFIC', 'conclusion_codes' => ['specific_pattern'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
        ]],
    ]);
    $catalog = new ApprovedMedicalContextCatalog($dir);

    $bothFired = array_column($catalog->groupsForConclusionCodes(['general_pattern', 'specific_pattern']), 'context_group_code');
    $onlyGenericFired = array_column($catalog->groupsForConclusionCodes(['general_pattern']), 'context_group_code');

    expect($bothFired)->toBe(['SPECIFIC']) // GENERIC excluded because SPECIFIC also matched
        ->and($onlyGenericFired)->toBe(['GENERIC']); // stands alone when SPECIFIC did not fire
});

test('a second, unrelated finding is never hidden by grouping/supersedes logic', function () {
    $dir = fixtureCatalogDir([
        'test.json' => ['schema_version' => '1', 'category' => 'CBC', 'groups' => [
            ['context_group_code' => 'ANEMIA_GROUP', 'conclusion_codes' => ['anemia_pattern'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
            ['context_group_code' => 'THROMBOCYTOSIS_GROUP', 'conclusion_codes' => ['thrombocytosis_pattern'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
        ]],
    ]);
    $catalog = new ApprovedMedicalContextCatalog($dir);

    $matched = array_column($catalog->groupsForConclusionCodes(['anemia_pattern', 'thrombocytosis_pattern']), 'context_group_code');

    expect($matched)->toBe(['ANEMIA_GROUP', 'THROMBOCYTOSIS_GROUP']);
});

test('multiple category files are merged into one catalog without cross-category interference', function () {
    $dir = fixtureCatalogDir([
        'cbc.json' => ['schema_version' => '1', 'category' => 'CBC', 'groups' => [
            ['context_group_code' => 'CBC_GROUP', 'conclusion_codes' => ['cbc_pattern'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
        ]],
        'diabetes.json' => ['schema_version' => '1', 'category' => 'DIABETES', 'groups' => [
            ['context_group_code' => 'DIABETES_GROUP', 'conclusion_codes' => ['diabetes_pattern'], 'review_status' => 'APPROVED', 'possible_causes' => [], 'common_symptoms' => [], 'general_next_steps' => [], 'red_flags' => []],
        ]],
    ]);
    $catalog = new ApprovedMedicalContextCatalog($dir);

    expect($catalog->groupsForConclusionCodes(['cbc_pattern']))->toHaveCount(1)
        ->and($catalog->groupsForConclusionCodes(['cbc_pattern'])[0]['context_group_code'])->toBe('CBC_GROUP')
        // A CBC conclusion code never resolves a DIABETES group and vice versa -
        // there is no shared/global fallback across categories.
        ->and($catalog->groupsForConclusionCodes(['diabetes_pattern']))->toHaveCount(1)
        ->and($catalog->groupsForConclusionCodes(['diabetes_pattern'])[0]['context_group_code'])->toBe('DIABETES_GROUP');
});
