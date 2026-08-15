<?php

namespace App\Services\Quiz\GeneralQuestions;

/**
 * Deterministic "random-looking" selection, used everywhere the generator needs a
 * stable subset/order (distractor choices, option lettering) without depending on
 * PHP's global RNG state — two runs over the same KBS data must always pick the same
 * distractors, or the Determinism requirement (generate twice -> identical output)
 * cannot hold. Ordering is by md5(seed|candidate), which is stable across processes
 * and platforms.
 */
final class DeterministicSelector
{
    /**
     * @template T
     *
     * @param  list<T>  $candidates
     * @param  callable(T): string  $keyFn
     * @return list<T>
     */
    public static function pick(array $candidates, int $count, string $seed, callable $keyFn): array
    {
        $ranked = $candidates;
        usort($ranked, static function ($a, $b) use ($seed, $keyFn): int {
            return md5($seed.'|'.$keyFn($a)) <=> md5($seed.'|'.$keyFn($b));
        });

        return array_slice($ranked, 0, $count);
    }
}
