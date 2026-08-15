<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * Deliberately narrow and hand-grounded: the current KBS's only clean example of "an
 * explicitly named additional value would let an incomplete pattern be evaluated more
 * completely" is liver rule LIVERX_R005 ("aminotransferase_elevation_without_complete
 * _pattern" — see kbs/knowledge_base/expanded_liver_rules.json and
 * core/liver_engine.py's `aminotransferase_incomplete` branch): when ALT or AST is
 * high but ALP (with its reference range) is unavailable, the R-value injury-pattern
 * classification cannot run. LIVERX_R005 itself is intentionally excluded from
 * LiverRuleTriggerCatalog (it is a data-quality/OR-shaped rule, not a clean trigger —
 * see SKIPPED_LOGIC_KEYS), so this family reads it directly rather than through
 * KbsRule. LIVER_R009 ("incomplete_liver_panel") was considered for this family too
 * but rejected: its requirement is a count threshold ("fewer than two markers
 * present"), not one specific named analyte, so it cannot produce a single defensible
 * MCQ answer.
 */
final class MissingSupportingInformationFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'MISSING_SUPPORTING_INFORMATION';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        $alp = $kb->analyte('alp');
        if ($alp === null) {
            return;
        }
        $notNeededForRValue = array_values(array_filter(
            $kb->allAnalytes(ReportTestCategory::LiverFunction),
            static fn (KbsAnalyte $a): bool => in_array($a->id, ['albumin', 'total_protein', 'prothrombin_time', 'inr'], true),
        ));
        if (count($notNeededForRValue) < 3) {
            return;
        }
        $distractors = DeterministicSelector::pick(
            $notNeededForRValue, 3, 'missing-info|liverx-r005',
            static fn (KbsAnalyte $a): string => $a->id,
        );

        yield new GeneratedGeneralQuestion(
            category: ReportTestCategory::LiverFunction,
            questionText: [
                'en' => 'When ALT or AST is high but the R-value injury-pattern classification cannot be completed, which additional value would let the current KBS evaluate the pattern more completely?',
                'ar' => 'عند ارتفاع ALT أو AST مع تعذّر إكمال تصنيف نمط الإصابة بنسبة R، ما القيمة الإضافية التي تتيح لنظام KBS الحالي تقييم النمط بشكل أكمل؟',
            ],
            options: $this->buildOptions(
                ['en' => "{$alp->name} (with its laboratory reference range)", 'ar' => "{$alp->nameAr} (مع مجالها المرجعي المخبري)"],
                array_map(static fn (KbsAnalyte $a): array => ['en' => $a->name, 'ar' => $a->nameAr], $distractors),
            ),
            correctOptionId: 'a',
            explanation: [
                'en' => "The KBS's R-value injury-pattern classification (rule LIVERX_R005) needs ALT/AST together with ALP and their reference ranges; without ALP the pattern is reported as incomplete rather than classified.",
                'ar' => 'يحتاج تصنيف نمط الإصابة بنسبة R في KBS (القاعدة LIVERX_R005) إلى ALT/AST مع ALP ومجالاتها المرجعية؛ فبدون ALP يُعرض النمط كغير مكتمل بدل تصنيفه.',
            ],
            sourceType: 'RULE',
            sourceId: 'LIVERX_R005',
            templateFamily: $this->code(),
            stableSourceKey: "LIVER_FUNCTION:LIVERX_R005:MISSING_SUPPORTING_INFORMATION:{$generatorVersion}",
            generatorVersion: $generatorVersion,
        );
    }
}
