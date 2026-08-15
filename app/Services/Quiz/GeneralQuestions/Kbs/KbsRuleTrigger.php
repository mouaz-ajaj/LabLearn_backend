<?php

namespace App\Services\Quiz\GeneralQuestions\Kbs;

/**
 * One (analyte, status) requirement that must hold for a rule to fire. `statuses`
 * holds more than one entry only for a `status_in` condition (e.g. hba1c "diabetes or
 * very_high") — every value in the list is an acceptable status for this trigger,
 * joined with "or" when rendered.
 */
final class KbsRuleTrigger
{
    /** @param list<string> $statuses */
    public function __construct(
        public readonly string $analyteId,
        public readonly array $statuses,
    ) {}

    public static function single(string $analyteId, string $status): self
    {
        return new self($analyteId, [$status]);
    }
}
