<?php

namespace App\Services\Quiz\GeneralQuestions\Kbs;

/**
 * Liver pattern rules are evaluated by hand-written Python (core/liver_engine.py), not
 * the declarative `when` engine regular/expanded rules use — there is no JSON to parse
 * their trigger conditions from. This catalog is a manual, careful transcription of
 * liver_engine.py's actual `if`/`elif` conditions into (analyte, status) pairs, cross-
 * checked against that file line by line (see backend/docs/general-question-bank.md).
 *
 * Only rules whose real trigger is a clean, honestly-representable AND-combination are
 * included. Rules that are genuinely OR-shaped (e.g. "albumin low OR INR high"),
 * ratio/fraction-based rather than simple status comparisons (e.g. direct-bilirubin
 * predominance), or purely data-quality/threshold rules (e.g. "fewer than two markers
 * present") are deliberately left out — representing them as a definite required
 * combination would misstate how the rule actually fires. Their `logic` keys are noted
 * below as explicitly skipped.
 *
 * Skipped: r_lt... wait — see SKIPPED_LOGIC_KEYS for the authoritative list and reasons.
 */
final class LiverRuleTriggerCatalog
{
    /**
     * logic key => [[analyte_id, status], ...] — all pairs are jointly (AND) required.
     * 'high'/'low'/'normal' are real classifier statuses; 'abnormal' is a descriptive
     * placeholder used only for total_protein's phrase text (the rule accepts either
     * low or high, so no single classifier status is definite).
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const TRIGGERS = [
        'r_gt_5' => [['alt', 'high']],
        'r_lt_2' => [['alp', 'high'], ['ggt', 'high']],
        'r_2_to_5' => [['alt', 'high'], ['alp', 'high']],
        'isolated_bilirubin' => [['total_bilirubin', 'high'], ['alt', 'normal'], ['ast', 'normal'], ['alp', 'normal']],
        'isolated_alt' => [['alt', 'high'], ['ast', 'normal'], ['alp', 'normal']],
        'isolated_ast' => [['ast', 'high'], ['alt', 'normal'], ['alp', 'normal']],
        'isolated_ggt' => [['ggt', 'high'], ['alt', 'normal'], ['ast', 'normal'], ['alp', 'normal']],
        'ast_alt_ratio_ge_2' => [['alt', 'high'], ['ast', 'high']],
        'combined_synthetic' => [['albumin', 'low'], ['inr', 'high']],
        'prolonged_pt' => [['prothrombin_time', 'high']],
        'abnormal_total_protein' => [['total_protein', 'abnormal']],
        'isolated_alp' => [['alp', 'high'], ['ggt', 'normal'], ['alt', 'normal'], ['ast', 'normal']],
        'alp_with_ggt' => [['alp', 'high'], ['ggt', 'high']],
        'discordant_liver' => [['alp', 'high'], ['ggt', 'normal']],
    ];

    /**
     * logic key => reason it is intentionally NOT in TRIGGERS.
     *
     * @var array<string, string>
     */
    public const SKIPPED_LOGIC_KEYS = [
        'direct_predominance' => 'Trigger is a computed bilirubin fraction (>50%), not a plain analyte status — cannot be honestly stated as a status pair.',
        'indirect_predominance' => 'Trigger is a computed bilirubin fraction (<50%), not a plain analyte status.',
        'synthetic_function' => 'OR-shaped (albumin low OR INR high, either alone fires it) — stating both as jointly required would misrepresent the rule.',
        'synthetic_normal_injury' => 'Mixed OR/AND condition (albumin OR INR abnormal, AND three normal enzymes) — too compound to state as one honest combination.',
        'aminotransferase_incomplete' => 'A data-quality/missing-information rule, not a findings combination; used separately for the Missing Supporting Information family.',
        'incomplete_liver' => 'A data-quality threshold rule ("fewer than two markers present"), not a specific findings combination.',
        'bilirubin_with_injury' => 'Partly OR-shaped ("at least one of ALT/AST/ALP/GGT high") — naming one specific analyte would overstate specificity.',
    ];

    /** @return list<KbsRuleTrigger> */
    public function triggersFor(string $logicKey): array
    {
        $pairs = self::TRIGGERS[$logicKey] ?? [];

        return array_map(
            static fn (array $pair): KbsRuleTrigger => KbsRuleTrigger::single($pair[0], $pair[1]),
            $pairs,
        );
    }

    public function isRepresentable(string $logicKey): bool
    {
        return array_key_exists($logicKey, self::TRIGGERS);
    }
}
