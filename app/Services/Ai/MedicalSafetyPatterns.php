<?php

namespace App\Services\Ai;

/**
 * Shared, narrow keyword safety net for the specific forbidden content categories
 * named across every AI-generated feature in this project (Phase 4C comparison
 * contextualization, Phase 4E result explanation): treatment/dosage instructions and
 * confirmed-cure/diagnosis claims. Extracted once so both response validators check
 * the exact same patterns rather than two copies drifting apart over time.
 *
 * Deliberately NOT presented as a comprehensive medical-content validator - regex
 * cannot verify medical correctness, only reject a known-bad shape. The primary
 * safety mechanism in every validator that uses this is structural allow-listing
 * (only identifiers Laravel itself supplied may be referenced), not this regex list.
 */
final class MedicalSafetyPatterns
{
    /**
     * Deliberately targets concrete dosage/instruction SIGNALS (a numeric dose, a
     * frequency schedule, an explicit "you must take" command) rather than bare nouns
     * like "medication" or "دواء" - those nouns appear legitimately throughout the
     * approved medical context catalog (e.g. "medication effect" as a cause, "medication
     * review" as a next step) and a bare-word block would reject that approved content
     * outright. See MedicalContext catalog docs for why medication is a valid cause/
     * next-step topic as long as no dose or instruction is attached.
     *
     * @return string[]
     */
    public static function forbidden(): array
    {
        return [
            // Numeric dosage amounts.
            '/\b\d+\s*(mg|mcg|milligram)s?\b/i',
            '/\d+\s*(ملغ|ميليغرام|مجم)/u',
            // Dosing frequency / schedule instructions.
            '/\b(take\s+\d|twice\s+daily|once\s+daily|every\s+\d+\s+hours|\d+\s*times?\s+(a|per)\s+day)\b/i',
            '/كل\s*\d+\s*(ساعة|ساعات)/u',
            // Explicit second-person instructions to take/start/stop a medication.
            '/\b(you (should|must|need to) (take|start|stop)|start(?:ing)?\s+(the\s+)?medication|stop(?:ping)?\s+(the\s+)?medication)\b/i',
            '/(احتاج ان تتناول|يجب ان تتناول|صف لك|خذ\s*(ال)?(قرص|كبسول|دواء)|ابدأ\s*(ال)?(دواء|العلاج)|أوقف\s*(ال)?(دواء|العلاج))/u',
            // Confirmed-cure / recovery claims (always unsafe, negation or not - "no
            // longer has" IS the forbidden claim, not a hedge against one).
            '/\b(cured|is cured|no longer has|disease (has )?disappeared|fully recovered)\b/i',
            '/(شُفي|تم الشفاء|لم يعد يعاني|اختفى المرض|تعافى تماما)/u',
        ];
    }

    /**
     * Negation cues checked immediately before a "confirmed diagnosis" occurrence.
     * Required separately from forbidden() because, unlike the other cure/recovery
     * phrases, "confirmed diagnosis" is unsafe only when asserted AFFIRMATIVELY (e.g.
     * "this is a confirmed diagnosis") and is exactly the required safe disclaimer
     * wording when negated (e.g. "does not represent a confirmed diagnosis" - the
     * limitations hedge every response must include per the system prompt).
     */
    private const DIAGNOSIS_CLAIM_NEGATION_CUES_EN = [
        'not a', 'not an', "isn't a", "isn't an", 'is not a', 'is not an',
        'not represent a', 'not represent an', "doesn't represent a", "doesn't represent an",
        'does not represent a', 'does not represent an', 'without a', 'without an', 'no ',
    ];

    private const DIAGNOSIS_CLAIM_NEGATION_CUES_AR = ['ليس', 'ليست', 'لا يمثل', 'لا تمثل', 'دون', 'بدون', 'غير'];

    public static function isSafe(string $text): bool
    {
        foreach (self::forbidden() as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return false;
            }
        }

        return ! self::hasUnhedgedDiagnosisClaim($text, 'confirmed diagnosis', self::DIAGNOSIS_CLAIM_NEGATION_CUES_EN)
            && ! self::hasUnhedgedDiagnosisClaim($text, 'تشخيص مؤكد', self::DIAGNOSIS_CLAIM_NEGATION_CUES_AR);
    }

    /** @param  string[]  $negationCues */
    private static function hasUnhedgedDiagnosisClaim(string $text, string $phrase, array $negationCues): bool
    {
        if (preg_match_all('/'.preg_quote($phrase, '/').'/iu', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($matches[0] as [, $byteOffset]) {
            $offset = mb_strlen(substr($text, 0, $byteOffset));
            $precedingWindow = mb_strtolower(mb_substr($text, max(0, $offset - 40), min($offset, 40)));
            $hedged = false;
            foreach ($negationCues as $cue) {
                if (str_contains($precedingWindow, mb_strtolower($cue))) {
                    $hedged = true;
                    break;
                }
            }
            if (! $hedged) {
                return true;
            }
        }

        return false;
    }
}
