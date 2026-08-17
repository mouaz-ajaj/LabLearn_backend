<?php

use App\Services\Ai\LanguagePurityChecker;

/**
 * Locks in the algorithm documented on LanguagePurityChecker: reject untranslated
 * English prose in an Arabic-designated field, without rejecting the medical
 * abbreviations/units/codes the product intentionally keeps in Latin script. Every
 * "false" case below is a real string captured live during the language-mixing
 * root-cause audit (KBS/Gemini output before this repair).
 */
test('genuine Arabic prose passes', function () {
    expect(LanguagePurityChecker::hasSufficientArabicProse('نمط فقر الدم المحتمل'))->toBeTrue()
        ->and(LanguagePurityChecker::hasSufficientArabicProse('هذا الشرح تعليمي فقط ولا يمثل تشخيصًا طبيًا.'))->toBeTrue();
});

test('Arabic prose with whitelisted medical abbreviations, units, and rule codes passes', function () {
    expect(LanguagePurityChecker::hasSufficientArabicProse(
        'الهيموغلوبين (HGB) بقيمة 9.5 g/dL أقل من المجال المرجعي وفقًا للقاعدة R001 وCBCX_R002.'
    ))->toBeTrue();
});

test('a lone Title-Case English analyte label next to its value is not rejected', function () {
    // Matches the audit's own worked example: "قيمة HGB هي 9.5 g/dL" is acceptable
    // mixed content, distinct from full untranslated English sentences.
    expect(LanguagePurityChecker::hasSufficientArabicProse(
        'استُنِد إلى الأدلة التالية: Hemoglobin 8.9 g/dL, MCV 71 fL.'
    ))->toBeTrue();
});

test('a fully English title mislabeled as Arabic is rejected', function () {
    expect(LanguagePurityChecker::hasSufficientArabicProse('Possible anemia pattern'))->toBeFalse()
        ->and(LanguagePurityChecker::hasSufficientArabicProse('Possible microcytic anemia pattern'))->toBeFalse();
});

test('a single English word with zero Arabic characters is rejected', function () {
    expect(LanguagePurityChecker::hasSufficientArabicProse('Hyperglycemia'))->toBeFalse();
});

test('a full English sentence mislabeled as Arabic is rejected', function () {
    expect(LanguagePurityChecker::hasSufficientArabicProse(
        'The result shows low hemoglobin which may indicate anemia.'
    ))->toBeFalse();
});

test('English prose mixed with a trailing Arabic word is still rejected', function () {
    expect(LanguagePurityChecker::hasSufficientArabicProse('The result shows انخفاض in hemoglobin.'))->toBeFalse();
});

test('pure numbers and units alone are treated as neutral, not English prose', function () {
    // A field consisting only of technical tokens (rare, but must not crash or
    // falsely reject) is not itself "English prose" - there is nothing to translate.
    expect(LanguagePurityChecker::hasSufficientArabicProse('9.5 g/dL R001'))->toBeTrue();
});
