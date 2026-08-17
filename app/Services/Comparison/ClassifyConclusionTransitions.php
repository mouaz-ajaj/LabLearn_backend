<?php

namespace App\Services\Comparison;

use App\Enums\ConclusionTransition;

/**
 * Deterministically classifies how each KBS conclusion_code's presence changed
 * across a comparison's chronologically-ordered reports - APPEARED/DISAPPEARED/
 * PERSISTED (plus TRANSIENT for a 3+ report edge case), never decided by Gemini.
 * Gemini receives this already-computed list and may only explain it.
 *
 * "Earliest"/"latest" mean the earliest/latest report that has a SUCCEEDED Analysis
 * in this comparison (a report with no succeeded Analysis contributes no conclusions
 * at all and is simply skipped, matching BuildReportComparison's own kbs_timeline
 * convention). If fewer than 2 reports have a succeeded Analysis, there is no
 * meaningful earliest-vs-latest comparison to make, and this returns an empty list
 * rather than guessing.
 */
class ClassifyConclusionTransitions
{
    /**
     * @param  array<int, array<string, mixed>>  $kbsTimeline  BuildReportComparison's kbs_timeline, oldest -> newest
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $kbsTimeline): array
    {
        $succeeded = array_values(array_filter(
            $kbsTimeline,
            fn (array $entry): bool => $entry['analysis_status'] === 'SUCCEEDED',
        ));

        if (count($succeeded) < 2) {
            return [];
        }

        $sequences = array_map(fn (array $entry): int => $entry['sequence'], $succeeded);
        $earliestSequence = min($sequences);
        $latestSequence = max($sequences);

        /** @var array<string, array{code:string, occurrences: array<int, array<string, mixed>>}> $byCode */
        $byCode = [];
        foreach ($succeeded as $entry) {
            foreach ($entry['conclusions'] as $conclusion) {
                $code = $conclusion['code'];
                $byCode[$code]['code'] ??= $code;
                $byCode[$code]['occurrences'][] = [
                    'sequence' => $entry['sequence'],
                    'level' => $conclusion['level'],
                    'title' => $conclusion['title'],
                    'summary' => $conclusion['summary'],
                    'rule_codes' => $conclusion['rule_codes'],
                ];
            }
        }

        $result = [];
        foreach ($byCode as $code => $data) {
            $occurrenceSequences = array_map(fn (array $o): int => $o['sequence'], $data['occurrences']);
            sort($occurrenceSequences);

            $presentInEarliest = in_array($earliestSequence, $occurrenceSequences, true);
            $presentInLatest = in_array($latestSequence, $occurrenceSequences, true);

            $transition = match (true) {
                $presentInEarliest && $presentInLatest => ConclusionTransition::Persisted,
                $presentInEarliest && ! $presentInLatest => ConclusionTransition::Disappeared,
                ! $presentInEarliest && $presentInLatest => ConclusionTransition::Appeared,
                default => ConclusionTransition::Transient,
            };

            $lastSequence = max($occurrenceSequences);
            $representative = collect($data['occurrences'])->firstWhere('sequence', $lastSequence);

            $result[] = [
                'conclusion_code' => $code,
                'transition' => $transition->value,
                'level' => $representative['level'],
                'title' => $representative['title'],
                'summary' => $representative['summary'],
                'rule_codes' => $representative['rule_codes'],
                'first_seen_sequence' => min($occurrenceSequences),
                'last_seen_sequence' => $lastSequence,
                'present_in_latest' => $presentInLatest,
                'occurrence_count' => count($occurrenceSequences),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['conclusion_code'] <=> $b['conclusion_code']);

        return $result;
    }
}
