<?php

namespace App\Services\Quiz\GeneralQuestions\Kbs;

/**
 * Bilingual display names for KBS condition_ids (the pattern a rule's `condition_id`
 * refers to). English is derived deterministically from the condition_id itself
 * (snake_case -> Title Case), which is always accurate since it is the KBS's own
 * identifier. Arabic has no equivalent short identifier in the raw KBS data (the
 * conditions.json/liver_conditions.json catalogs mix incomplete/placeholder Arabic —
 * see Phase 3B.3 notes), so this is a hand-authored translation of every condition_id
 * actually used by an active rule this generator draws from — the same authoring
 * approach already used for the Case-Specific templates in Phase 3B.3.
 */
final class ConditionNameCatalog
{
    /** @var array<string, string> */
    private const AR_NAMES = [
        // CBC
        'possible_anemia_pattern' => 'نمط محتمل لفقر الدم',
        'microcytic_anemia_pattern' => 'نمط فقر دم صغير الكريات',
        'macrocytic_anemia_pattern' => 'نمط فقر دم كبير الكريات',
        'normocytic_anemia_pattern' => 'نمط فقر دم طبيعي حجم الكريات',
        'possible_polycythemia_pattern' => 'نمط محتمل لكثرة الكريات الحمر',
        'possible_infection_inflammation_pattern' => 'نمط محتمل لعدوى أو التهاب',
        'possible_low_wbc_pattern' => 'نمط انخفاض كريات الدم البيضاء',
        'possible_neutrophilia_pattern' => 'نمط ارتفاع العدلات',
        'possible_neutropenia_pattern' => 'نمط نقص العدلات',
        'possible_lymphocytosis_pattern' => 'نمط ارتفاع اللمفاويات',
        'possible_eosinophilia_pattern' => 'نمط ارتفاع الحمضات',
        'possible_thrombocytopenia_pattern' => 'نمط نقص الصفائح الدموية',
        'possible_thrombocytosis_pattern' => 'نمط ارتفاع الصفائح الدموية',
        'possible_combined_cytopenia_pattern' => 'نمط نقص مشترك في خطوط الدم',
        'hypochromic_red_cell_pattern' => 'نمط نقص صباغ الكريات الحمر',
        'anisocytosis_signal' => 'إشارة تفاوت أحجام الكريات الحمر',
        'reticulocytosis_signal' => 'إشارة ارتفاع الخلايا الشبكية',
        'reticulocytopenia_signal' => 'إشارة انخفاض الخلايا الشبكية',
        'anemia_with_reduced_reticulocyte_response' => 'فقر دم مع استجابة شبكية منخفضة',
        'anemia_with_increased_reticulocyte_response' => 'فقر دم مع استجابة شبكية مرتفعة',
        'possible_lymphopenia_pattern' => 'نمط نقص اللمفاويات',
        'possible_monocytosis_pattern' => 'نمط ارتفاع الوحيدات',
        'possible_monocytopenia_pattern' => 'نمط نقص الوحيدات',
        'possible_basophilia_pattern' => 'نمط ارتفاع القعدات',
        'possible_basopenia_pattern' => 'نمط انخفاض القعدات',
        'pancytopenia_pattern' => 'نمط نقص شامل في خطوط الدم',
        'multilineage_elevation_pattern' => 'نمط ارتفاع متعدد الخطوط',
        'microcytosis_without_anemia_pattern' => 'نمط صغر الكريات دون فقر دم',
        'macrocytosis_without_anemia_pattern' => 'نمط كبر الكريات دون فقر دم',
        'relative_absolute_differential_discordance' => 'تعارض بين النسبة والعدد المطلق في التفريق',
        'platelet_count_size_pattern' => 'نمط مشترك لعدد الصفائح وحجمها',
        // DIABETES
        'possible_hypoglycemia_pattern' => 'نمط محتمل لانخفاض السكر بالدم',
        'possible_prediabetes_pattern' => 'نمط محتمل لمقدمات السكري',
        'possible_diabetes_pattern' => 'نمط محتمل للسكري',
        'possible_discordant_glucose_results' => 'نمط تباين في نتائج السكر',
        'possible_a1c_interference_context' => 'سياق محتمل لتأثر موثوقية HbA1c',
        // LIVER_FUNCTION
        'hepatocellular_injury_pattern' => 'نمط إصابة كبدية خلوية',
        'cholestatic_injury_pattern' => 'نمط إصابة ركودية صفراوية',
        'mixed_liver_injury_pattern' => 'نمط إصابة كبدية مختلطة',
        'isolated_bilirubin_elevation' => 'ارتفاع معزول في البيليروبين',
        'isolated_alt_elevation' => 'ارتفاع معزول في ALT',
        'isolated_ast_elevation' => 'ارتفاع معزول في AST',
        'isolated_ggt_elevation' => 'ارتفاع معزول في GGT',
        'ast_predominant_aminotransferase_pattern' => 'نمط غلبة AST بين ناقلات الأمين',
        'combined_synthetic_function_signal' => 'مؤشر مركب لخلل الوظيفة التصنيعية',
        'prolonged_pt_pattern' => 'نمط إطالة زمن البروثرومبين',
        'abnormal_total_protein_pattern' => 'نمط اضطراب البروتين الكلي',
        'isolated_alp_elevation' => 'ارتفاع معزول في ALP',
        'alp_ggt_elevation' => 'ارتفاع مشترك في ALP وGGT',
        'discordant_liver_results' => 'تعارض في نتائج فحوصات الكبد',
    ];

    /** Lab abbreviations that must stay upper-case even mid-title, e.g. "isolated_alt_elevation" -> "Isolated ALT elevation", not "Isolated alt elevation". */
    private const KNOWN_ABBREVIATIONS = [
        'alt', 'ast', 'alp', 'ggt', 'pt', 'inr', 'wbc', 'rbc', 'mcv', 'mch', 'mchc', 'rdw', 'mpv', 'anc', 'alc', 'aec',
    ];

    public function nameEn(string $conditionId): string
    {
        $words = explode(' ', str_replace('_', ' ', $conditionId));
        $words = array_map(
            static fn (string $word): string => in_array($word, self::KNOWN_ABBREVIATIONS, true) ? strtoupper($word) : $word,
            $words,
        );

        return ucfirst(implode(' ', $words));
    }

    public function nameAr(string $conditionId): string
    {
        return self::AR_NAMES[$conditionId] ?? $this->nameEn($conditionId);
    }
}
