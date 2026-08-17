<?php

namespace App\Services\Ai;

/**
 * Conservative, token-aware check for whether a string that is supposed to be
 * Arabic prose actually contains genuine Arabic prose, rather than untranslated
 * English echoed back by Gemini (the exact failure mode the language-mixing audit
 * confirmed live: Gemini leaving a KBS-sourced English title/label untranslated in
 * an otherwise-Arabic response).
 *
 * This is deliberately NOT "reject any Latin character" - that would reject
 * required content such as HGB, MCV, HbA1c, ALT, AST, R001, CBCX_R002, g/dL, and
 * numeric values, all of which the product intentionally keeps in Latin script
 * inside Arabic prose (see the Blueprint's Localization section and the Gemini
 * prompt's own explicit whitelist).
 *
 * Algorithm:
 *  1. Strip every allowed technical token from the text: numeric values, common
 *     laboratory units, HbA1c, and any Latin token that is either ALL-CAPS
 *     (HGB, MCV, ALT, CBC, RBC...) or an alphanumeric code (R001, CBCX_R002,
 *     LIVERX_R010...). These are exactly the categories the prompt tells Gemini
 *     it may keep in Latin script.
 *  2. In what remains, a run of two or more consecutive Latin words that is NOT
 *     itself an allowed token (i.e., ordinary lowercase-starting English words
 *     such as "the result" or "microcytic anemia") is treated as untranslated
 *     English prose and fails the check. A single leftover Title-Case English
 *     word (e.g. "Hemoglobin" used as a bare analyte label next to its Arabic
 *     translation, or alone) is NOT rejected by this rule - the audit's own
 *     worked examples treat a lone analyte-name-plus-value as acceptable mixed
 *     content, distinct from full English sentences/titles.
 *  3. Separately, if after stripping technical tokens there is still meaningful
 *     remaining text (>= 4 characters) but zero Arabic-script characters
 *     anywhere in the original string, the check fails - this catches a field
 *     that is *entirely* an untranslated English title/sentence too short or too
 *     single-worded to trip rule 2 (e.g. "Hyperglycemia" or "Possible anemia
 *     pattern" as a whole `title` value).
 *
 * Known false-positive/false-negative limitations (documented, not hidden):
 *  - A genuinely Arabic sentence that happens to contain two consecutive
 *    Title-Case English proper nouns with nothing else between them (rare in
 *    practice) could be flagged. This is an intentionally conservative trade-off
 *    given the confirmed real-world failure mode is far more common and severe.
 *  - An uncommon lab unit not in the stripped-unit list could survive stripping
 *    and, combined with another leftover word, trigger a false rejection. The
 *    unit list covers every unit currently used by the KBS contract; if a new
 *    unit is added to the KBS this list should be extended alongside it.
 *  - This is not a general-purpose language detector and must never be used to
 *    validate free-form user input or unrelated content - it is scoped
 *    specifically to Gemini's structured result-explanation/comparison output.
 */
final class LanguagePurityChecker
{
    private const TECHNICAL_TOKEN_PATTERN = '/
        \d+(?:[.,]\d+)?%?
        | \b(?:g\/dL|mg\/dL|mmol\/L|mmol\/mol|U\/L|IU\/L|mIU\/L|ng\/mL|pg|fL|mcL|µL|μL|K\/uL|k\/uL|10\^\d+\/[a-zA-Z]+|cells\/mcL|mcg\/dL)\b
        | \bHbA1c\b
        | \b[A-Z]{2,}[0-9][A-Z0-9_]*\b
        | \b[A-Z]{2,8}\b
    /ux';

    public static function hasSufficientArabicProse(string $text): bool
    {
        $stripped = preg_replace(self::TECHNICAL_TOKEN_PATTERN, ' ', $text) ?? $text;

        if (preg_match('/\b[a-z][a-zA-Z]{2,}\b(?:[\s\-]+[a-zA-Z]{2,}\b){1,}/u', $stripped) === 1) {
            return false;
        }

        $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $meaningfulLength = mb_strlen(trim($stripped));

        if ($meaningfulLength >= 4 && $arabicChars === 0) {
            return false;
        }

        return true;
    }
}
