<?php

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\GeneralQuestionGenerator;
use App\Services\Quiz\GeneralQuestions\GeneralQuestionValidator;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;
use App\Services\Quiz\GeneralQuestions\Kbs\LiverRuleTriggerCatalog;

function generalQuestionsKbsPath(): string
{
    return (string) config('quiz.kbs_knowledge_base_path');
}

function generalQuestionsKb(): KbsKnowledgeBase
{
    return KbsKnowledgeBase::load(generalQuestionsKbsPath(), new LiverRuleTriggerCatalog);
}

function generalQuestionsResult(): array
{
    $generator = new GeneralQuestionGenerator(generalQuestionsKbsPath(), 'v1', new GeneralQuestionValidator);

    return $generator->generate();
}

// --- KBS extraction correctness -------------------------------------------------

test('the KBS loader reads real panel membership, required inputs, and analyte metadata', function () {
    $kb = generalQuestionsKb();

    $cbcPanel = array_map(fn ($a) => $a->id, $kb->panelAnalytes(ReportTestCategory::Cbc));
    expect($cbcPanel)->toContain('hemoglobin', 'wbc', 'platelets', 'mcv')
        ->and(count($cbcPanel))->toBe(19);

    expect($kb->requiredAnalyteIds(ReportTestCategory::Cbc))->toBe(['hemoglobin', 'wbc', 'platelets']);
    expect($kb->requiredAnalyteIds(ReportTestCategory::Diabetes))->toBe([]);
    expect($kb->requiredAnalyteIds(ReportTestCategory::LiverFunction))->toContain('alt', 'ast', 'alp', 'total_bilirubin', 'albumin');

    $hemoglobin = $kb->analyte('hemoglobin');
    expect($hemoglobin->name)->toBe('Hemoglobin')
        ->and($hemoglobin->shortName)->toBe('Hb')
        ->and($hemoglobin->nameAr)->not->toBeEmpty();
});

test('ambiguous aliases from analyte_disambiguation.json are excluded from safe aliases', function () {
    $kb = generalQuestionsKb();
    $neutrophilsPercent = $kb->analyte('neutrophils_percent');

    expect($neutrophilsPercent->safeAliases)->not->toContain('Neutrophils', 'Neutrophil', 'Neut')
        ->and($neutrophilsPercent->safeAliases)->not->toBeEmpty();
});

test('active rules are loaded with correctly resolved joint triggers, and any-one-of rules are excluded from clean pairs', function () {
    $kb = generalQuestionsKb();
    $cbcRules = collect($kb->rules(ReportTestCategory::Cbc));

    $r002 = $cbcRules->firstWhere('code', 'R002');
    expect($r002)->not->toBeNull()
        ->and($r002->jointAnalyteIds())->toEqualCanonicalizing(['hemoglobin', 'mcv'])
        ->and($r002->isCleanPair())->toBeTrue();

    // R001 is "any one of hemoglobin/hematocrit/rbc low" (min_matched=1 of 3) - not a
    // joint requirement, so it must never be treated as a clean/describable combination.
    $r001 = $cbcRules->firstWhere('code', 'R001');
    expect($r001->jointTriggers)->toBe([]);
});

test('inactive rules are never loaded', function () {
    $kb = generalQuestionsKb();
    $diabetesCodes = collect($kb->rules(ReportTestCategory::Diabetes))->pluck('code');

    // R022 (legacy hba1c very_high threshold) is explicitly active:false in rules.json.
    expect($diabetesCodes)->not->toContain('R022');
});

test('the one genuine cross-panel rule is detected without hardcoding its rule code', function () {
    $kb = generalQuestionsKb();
    $glux001 = collect($kb->rules(ReportTestCategory::Diabetes))->firstWhere('code', 'GLUX_R001');

    expect($glux001)->not->toBeNull();
    $crossIds = $kb->crossPanelAnalyteIds($glux001);
    expect($crossIds)->toBe(['hemoglobin']);
});

// --- Template family output quality ----------------------------------------------

test('every generated question has exactly 4 unique bilingual options and a valid correct answer', function () {
    $result = generalQuestionsResult();
    $validator = new GeneralQuestionValidator;
    $checked = 0;
    foreach ($result['questionsByCategory'] as $questions) {
        foreach ($questions as $question) {
            expect($validator->validateQuestion($question))->toBe([]);
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThan(300);
});

test('General questions never cross category: every question is stored under the category it was generated for', function () {
    $result = generalQuestionsResult();
    foreach (ReportTestCategory::cases() as $category) {
        foreach ($result['questionsByCategory'][$category->value] as $question) {
            expect($question->category)->toBe($category);
        }
    }
});

test('each category comfortably exceeds the minimum coverage of 14 questions', function () {
    $result = generalQuestionsResult();
    foreach (ReportTestCategory::cases() as $category) {
        expect(count($result['questionsByCategory'][$category->value]))->toBeGreaterThanOrEqual(14);
    }
});

test('at least 10 distinct template families produced at least one question', function () {
    $result = generalQuestionsResult();
    $families = [];
    foreach ($result['questionsByCategory'] as $questions) {
        foreach ($questions as $question) {
            $families[$question->templateFamily] = true;
        }
    }
    expect(count($families))->toBeGreaterThanOrEqual(10);
});

// --- Determinism -------------------------------------------------------------------

test('generating the bank twice from the same KBS files produces an identical set of stable source keys', function () {
    $first = generalQuestionsResult();
    $second = generalQuestionsResult();

    $keysOf = function (array $byCategory): array {
        $keys = [];
        foreach ($byCategory as $questions) {
            foreach ($questions as $question) {
                $keys[] = $question->stableSourceKey;
            }
        }

        return $keys;
    };

    expect($keysOf($first['questionsByCategory']))->toBe($keysOf($second['questionsByCategory']));
});

// --- Duplicate prevention -----------------------------------------------------------

test('no two generated questions share a stable source key', function () {
    $result = generalQuestionsResult();
    $keys = [];
    foreach ($result['questionsByCategory'] as $questions) {
        foreach ($questions as $question) {
            $keys[] = $question->stableSourceKey;
        }
    }
    expect(count($keys))->toBe(count(array_unique($keys)));
});

test('no two generated questions in the same category have the same stem and correct answer', function () {
    $result = generalQuestionsResult();
    foreach ($result['questionsByCategory'] as $questions) {
        $textKeys = array_map(fn ($q) => $q->normalizedTextKey(), $questions);
        expect(count($textKeys))->toBe(count(array_unique($textKeys)));
    }
});

test('no generated question has duplicate option text within itself', function () {
    $result = generalQuestionsResult();
    foreach ($result['questionsByCategory'] as $questions) {
        foreach ($questions as $question) {
            $enTexts = array_map(fn ($o) => mb_strtolower(trim($o['en'])), $question->options);
            expect(count($enTexts))->toBe(count(array_unique($enTexts)));
        }
    }
});

test('the bank-wide validator passes on the real generated bank with zero errors', function () {
    $result = generalQuestionsResult();
    $errors = (new GeneralQuestionValidator)->validateBank($result['questionsByCategory']);
    expect($errors)->toBe([]);
});

// --- Validator catches deliberately malformed candidates --------------------------

test('the per-question validator rejects a candidate with duplicate option text', function () use (&$sampleQuestion) {
    $result = generalQuestionsResult();
    $sample = $result['questionsByCategory']['CBC'][0];
    $badOptions = $sample->options;
    $badOptions['b'] = $badOptions['a'];
    $bad = new GeneratedGeneralQuestion(
        category: $sample->category, questionText: $sample->questionText, options: $badOptions,
        correctOptionId: 'a', explanation: $sample->explanation, sourceType: $sample->sourceType,
        sourceId: $sample->sourceId, templateFamily: $sample->templateFamily,
        stableSourceKey: 'test-bad-key', generatorVersion: 'v1',
    );
    $errors = (new GeneralQuestionValidator)->validateQuestion($bad);
    expect($errors)->not->toBe([]);
});

test('the per-question validator rejects DEV FIXTURE placeholder text', function () {
    $sample = generalQuestionsResult()['questionsByCategory']['CBC'][0];
    $bad = new GeneratedGeneralQuestion(
        category: $sample->category,
        questionText: ['en' => '[DEV FIXTURE] placeholder', 'ar' => 'نص'],
        options: $sample->options, correctOptionId: 'a', explanation: $sample->explanation,
        sourceType: $sample->sourceType, sourceId: $sample->sourceId, templateFamily: $sample->templateFamily,
        stableSourceKey: 'test-bad-key-2', generatorVersion: 'v1',
    );
    expect((new GeneralQuestionValidator)->validateQuestion($bad))->not->toBe([]);
});
