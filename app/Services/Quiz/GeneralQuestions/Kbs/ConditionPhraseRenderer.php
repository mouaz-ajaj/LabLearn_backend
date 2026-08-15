<?php

namespace App\Services\Quiz\GeneralQuestions\Kbs;

/**
 * Deterministically renders a natural bilingual sentence describing a set of jointly-
 * required (analyte, status) trigger pairs — e.g. "Low Hemoglobin together with low
 * MCV". Used by the Pattern/Condition Recognition and Rule Conclusion Matching
 * template families so every generated sentence is built the same way from real
 * trigger data, in both languages, instead of being individually hand-written per rule.
 */
final class ConditionPhraseRenderer
{
    /** Short adjectives that read naturally placed BEFORE the analyte name ("low Hemoglobin"). */
    private const PREFIX_STATUS_EN = [
        'low' => 'low',
        'high' => 'high',
        'normal' => 'normal',
    ];

    /**
     * Arabic idafa (noun-construct) forms, not adjectives — "ارتفاع {label}" ("a rise
     * in {label}") rather than "{label} مرتفع" ("{label} [is] high"). An adjective would
     * need to agree in gender with the analyte name (e.g. feminine "نسبة" vs masculine
     * "عدد"), which a single fixed word cannot do correctly for every analyte; an idafa
     * head noun does not need to agree, so this reads correctly regardless of the
     * analyte name's gender.
     */
    private const PREFIX_STATUS_AR = [
        'low' => 'انخفاض',
        'high' => 'ارتفاع',
        'normal' => 'استقرار',
    ];

    /** Longer phrases that read naturally placed AFTER the analyte name ("HbA1c in the diabetes range"). */
    private const SUFFIX_STATUS_EN = [
        'abnormal' => 'outside its supplied reference range',
        'diabetes' => 'in the diabetes range',
        'prediabetes' => 'in the prediabetes range',
        'very_high' => 'markedly elevated',
        'indeterminate' => 'indeterminate',
    ];

    private const SUFFIX_STATUS_AR = [
        'abnormal' => 'خارج المجال المرجعي المزوَّد',
        'diabetes' => 'ضمن مجال السكري',
        'prediabetes' => 'ضمن مجال ما قبل السكري',
        'very_high' => 'مرتفع بشكل ملحوظ',
        'indeterminate' => 'غير حاسمة',
    ];

    /**
     * @param  list<KbsRuleTrigger>  $triggers
     * @param  array<string, KbsAnalyte>  $analytesById
     */
    public function renderEn(array $triggers, array $analytesById): string
    {
        $parts = array_map(
            fn (KbsRuleTrigger $trigger): string => $this->renderOneEn($trigger, $analytesById),
            $triggers,
        );

        return ucfirst($this->join($parts, ', ', ' together with '));
    }

    /**
     * @param  list<KbsRuleTrigger>  $triggers
     * @param  array<string, KbsAnalyte>  $analytesById
     */
    public function renderAr(array $triggers, array $analytesById): string
    {
        $parts = array_map(
            fn (KbsRuleTrigger $trigger): string => $this->renderOneAr($trigger, $analytesById),
            $triggers,
        );

        return $this->join($parts, '، ', ' مع ');
    }

    private function renderOneEn(KbsRuleTrigger $trigger, array $analytesById): string
    {
        $label = $analytesById[$trigger->analyteId]?->name ?? $trigger->analyteId;
        $prefixWords = [];
        $suffixWords = [];
        foreach ($trigger->statuses as $status) {
            if (isset(self::PREFIX_STATUS_EN[$status])) {
                $prefixWords[] = self::PREFIX_STATUS_EN[$status];
            } else {
                $suffixWords[] = self::SUFFIX_STATUS_EN[$status] ?? $status;
            }
        }
        $text = $prefixWords !== [] ? implode(' or ', $prefixWords).' '.$label : $label;
        if ($suffixWords !== []) {
            $text .= ($prefixWords !== [] ? ' and ' : ' is ').implode(' or ', $suffixWords);
        }

        return $text;
    }

    private function renderOneAr(KbsRuleTrigger $trigger, array $analytesById): string
    {
        $label = $analytesById[$trigger->analyteId]?->nameAr ?? $trigger->analyteId;
        // Real trigger data never mixes a prefix-style status (low/high/normal) with a
        // suffix-style one (diabetes/prediabetes/...) within the same status_in list,
        // so treating the whole trigger as one style or the other (never both at once)
        // keeps the label from being repeated per status.
        $isPrefixStyle = isset(self::PREFIX_STATUS_AR[$trigger->statuses[0]]);
        if ($isPrefixStyle) {
            $status = $trigger->statuses[0];
            $noun = self::PREFIX_STATUS_AR[$status];

            return $status === 'normal' ? "{$noun} {$label} ضمن المعدل الطبيعي" : "{$noun} {$label}";
        }
        $words = array_map(
            static fn (string $status): string => self::SUFFIX_STATUS_AR[$status] ?? $status,
            $trigger->statuses,
        );

        return "{$label} ".implode(' أو ', $words);
    }

    /** @param list<string> $parts */
    private function join(array $parts, string $separator, string $lastSeparator): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);

        return implode($separator, $parts).$lastSeparator.$last;
    }
}
