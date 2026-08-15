<?php

namespace App\Services\Quiz\CaseSpecific;

use App\Enums\ReportTestCategory;

/**
 * DEVELOPMENT / INITIAL CONTENT — not a claim of full medical review coverage.
 *
 * A small, deterministic set of Case-Specific templates, each tied to one real,
 * currently-active KBS rule_code (verified against the live kbs/core engine — not
 * assumed). This intentionally covers only a handful of rules per category, not the
 * full KBS rule catalog (~50+ rules across CBC/DIABETES/LIVER_FUNCTION); expanding
 * coverage is future content work, exactly like the Phase 3B.2 General Question Bank.
 * A template that never matches any fired rule for a given Analysis is simply never
 * used — see CaseSpecificQuestionBuilder, which never fabricates a question to fill
 * a gap.
 */
final class CaseSpecificTemplateCatalog
{
    /** @return list<CaseSpecificTemplate> */
    public function templatesFor(ReportTestCategory $category): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (CaseSpecificTemplate $template): bool => $template->category === $category,
        ));
    }

    /** @return list<CaseSpecificTemplate> */
    private function all(): array
    {
        return [
            // --- CBC ---
            new CaseSpecificTemplate(
                id: 'cbc-cs-r001',
                version: 1,
                category: ReportTestCategory::Cbc,
                ruleCode: 'R001',
                conclusionCode: 'possible_anemia_pattern',
                questionText: [
                    'en' => "In this report's verified results, {label} was measured at {value} {unit}, which the deterministic analysis flagged as low. Which educational pattern does this specific finding support?",
                    'ar' => 'في القيم المؤكدة لهذا التقرير، تم قياس {label} بقيمة {value} {unit} وصنّفها التحليل الحتمي بأنها منخفضة. ما النمط التعليمي الذي يدعمه هذا الاكتشاف تحديدًا؟',
                ],
                options: [
                    'a' => ['en' => 'A possible anemia pattern', 'ar' => 'نمط محتمل لفقر الدم'],
                    'b' => ['en' => 'A possible infection or inflammation pattern', 'ar' => 'نمط محتمل لعدوى أو التهاب'],
                    'c' => ['en' => 'A possible thrombocytopenia pattern', 'ar' => 'نمط محتمل لنقص الصفائح الدموية'],
                    'd' => ['en' => 'No abnormal pattern was detected for this value', 'ar' => 'لم يتم رصد أي نمط غير طبيعي لهذه القيمة'],
                ],
                correctOptionId: 'a',
                explanation: [
                    'en' => 'The analysis flagged {label} as low, which is exactly the evidence rule R001 uses to support a possible anemia pattern in this report — a low hemoglobin, hematocrit, or RBC value can each independently support this pattern.',
                    'ar' => 'صنّف التحليل {label} بأنها منخفضة، وهو بالضبط الدليل الذي تعتمد عليه القاعدة R001 لدعم نمط محتمل لفقر الدم في هذا التقرير — يمكن لانخفاض الهيموغلوبين أو الهيماتوكريت أو خلايا الدم الحمراء أن يدعم هذا النمط بشكل مستقل.',
                ],
            ),
            new CaseSpecificTemplate(
                id: 'cbc-cs-r002',
                version: 1,
                category: ReportTestCategory::Cbc,
                ruleCode: 'R002',
                conclusionCode: 'microcytic_anemia_pattern',
                questionText: [
                    'en' => "This report's evidence shows {label} at {value} {unit} together with a low MCV. Which more specific anemia subtype does this combination support?",
                    'ar' => 'يُظهر دليل هذا التقرير {label} بقيمة {value} {unit} إلى جانب انخفاض حجم الكرية الوسطي (MCV). ما النوع الأكثر تحديدًا لفقر الدم الذي يدعمه هذا المزيج؟',
                ],
                options: [
                    'a' => ['en' => 'Microcytic anemia pattern', 'ar' => 'نمط فقر دم صغير الكريات'],
                    'b' => ['en' => 'Macrocytic anemia pattern', 'ar' => 'نمط فقر دم كبير الكريات'],
                    'c' => ['en' => 'Normocytic anemia pattern', 'ar' => 'نمط فقر دم طبيعي الحجم'],
                    'd' => ['en' => 'Possible polycythemia pattern', 'ar' => 'نمط محتمل لكثرة الكريات الحمر'],
                ],
                correctOptionId: 'a',
                explanation: [
                    'en' => 'Rule R002 requires both a low hemoglobin AND a low MCV together — small (low-volume) red blood cells alongside low hemoglobin is exactly what defines a microcytic anemia pattern, distinct from macrocytic (high MCV) or normocytic (normal MCV) patterns.',
                    'ar' => 'تتطلب القاعدة R002 انخفاض الهيموغلوبين مع انخفاض MCV معًا — فوجود خلايا دم حمراء صغيرة الحجم مع انخفاض الهيموغلوبين هو بالضبط ما يُعرّف نمط فقر الدم صغير الكريات، ويختلف عن النمط كبير الكريات (MCV مرتفع) أو طبيعي الحجم (MCV طبيعي).',
                ],
            ),

            // --- DIABETES ---
            new CaseSpecificTemplate(
                id: 'diabetes-cs-r020',
                version: 1,
                category: ReportTestCategory::Diabetes,
                ruleCode: 'R020',
                conclusionCode: 'possible_diabetes_pattern',
                questionText: [
                    'en' => "This report's {label} result was {value} {unit}, which the analysis classified as meeting a diabetes-range threshold. What does this specific classification support, on its own?",
                    'ar' => 'كانت نتيجة {label} في هذا التقرير {value} {unit}، وصنّفها التحليل بأنها ضمن عتبة نطاق السكري. ما الذي تدعمه هذه التصنيفة تحديدًا بمفردها؟',
                ],
                options: [
                    'a' => ['en' => 'A possible diabetes pattern requiring confirmation', 'ar' => 'نمط محتمل للسكري يحتاج إلى تأكيد'],
                    'b' => ['en' => 'A confirmed diagnosis of diabetes', 'ar' => 'تشخيص مؤكد لمرض السكري'],
                    'c' => ['en' => 'A possible hypoglycemia pattern', 'ar' => 'نمط محتمل لانخفاض السكر بالدم'],
                    'd' => ['en' => 'No glucose-related pattern at all', 'ar' => 'لا يوجد أي نمط متعلق بالسكر إطلاقًا'],
                ],
                correctOptionId: 'a',
                explanation: [
                    'en' => 'Rule R020 fires when a single glucose-related result (fasting glucose, random glucose, or HbA1c) meets a diabetes-range threshold. This supports a possible pattern only — a single result is never treated as a confirmed diagnosis in this educational tool.',
                    'ar' => 'تُفعَّل القاعدة R020 عند بلوغ نتيجة واحدة متعلقة بالسكر (الصيام، العشوائي، أو HbA1c) عتبة نطاق السكري. وهذا يدعم نمطًا محتملًا فقط — فلا تُعامل نتيجة واحدة أبدًا كتشخيص مؤكد ضمن هذه الأداة التعليمية.',
                ],
            ),
            new CaseSpecificTemplate(
                id: 'diabetes-cs-r017',
                version: 1,
                category: ReportTestCategory::Diabetes,
                ruleCode: 'R017',
                conclusionCode: 'possible_hypoglycemia_pattern',
                questionText: [
                    'en' => "This report's {label} result was {value} {unit}, flagged as low by the analysis. Which pattern does a low glucose-related result support?",
                    'ar' => 'كانت نتيجة {label} في هذا التقرير {value} {unit}، وصنّفها التحليل بأنها منخفضة. ما النمط الذي تدعمه نتيجة منخفضة متعلقة بالسكر؟',
                ],
                options: [
                    'a' => ['en' => 'A possible hypoglycemia pattern', 'ar' => 'نمط محتمل لانخفاض السكر بالدم'],
                    'b' => ['en' => 'A possible diabetes pattern', 'ar' => 'نمط محتمل للسكري'],
                    'c' => ['en' => 'A possible prediabetes pattern', 'ar' => 'نمط محتمل لمقدمات السكري'],
                    'd' => ['en' => 'A discordant glucose results pattern', 'ar' => 'نمط تباين في نتائج السكر'],
                ],
                correctOptionId: 'a',
                explanation: [
                    'en' => 'Rule R017 fires when fasting, random, or postprandial glucose is flagged low, which supports a possible hypoglycemia pattern — the opposite end of the glucose range from diabetes/prediabetes patterns.',
                    'ar' => 'تُفعَّل القاعدة R017 عند تصنيف سكر الصيام أو العشوائي أو بعد الأكل بأنه منخفض، وهو ما يدعم نمطًا محتملًا لانخفاض السكر — وهو الطرف المعاكس لنمط السكري أو مقدماته على مقياس السكر.',
                ],
            ),

            // --- LIVER_FUNCTION ---
            new CaseSpecificTemplate(
                id: 'liver-cs-r001',
                version: 1,
                category: ReportTestCategory::LiverFunction,
                ruleCode: 'LIVER_R001',
                conclusionCode: 'hepatocellular_injury_pattern',
                questionText: [
                    'en' => "This report's {label} was {value} {unit}, flagged as high, and the R-ratio computed from ALT/ALP relative to their upper reference limits was above 5. Which liver injury pattern does this combination support?",
                    'ar' => 'كانت {label} في هذا التقرير {value} {unit} وصُنّفت مرتفعة، وكانت نسبة R المحسوبة من ALT/ALP نسبةً إلى الحد الأعلى المرجعي لكل منهما أكبر من 5. ما نمط إصابة الكبد الذي يدعمه هذا المزيج؟',
                ],
                options: [
                    'a' => ['en' => 'A hepatocellular injury pattern', 'ar' => 'نمط إصابة كبدية خلوية'],
                    'b' => ['en' => 'A cholestatic injury pattern', 'ar' => 'نمط إصابة ركودية صفراوية'],
                    'c' => ['en' => 'A mixed liver injury pattern', 'ar' => 'نمط إصابة كبدية مختلطة'],
                    'd' => ['en' => 'An isolated bilirubin elevation', 'ar' => 'ارتفاع معزول في البيليروبين'],
                ],
                correctOptionId: 'a',
                explanation: [
                    'en' => 'An R-ratio above 5, together with a high ALT, is the specific evidence combination rule LIVER_R001 uses to support a hepatocellular injury pattern — distinct from the cholestatic pattern (R below 2) or the mixed pattern (R between 2 and 5).',
                    'ar' => 'إن نسبة R الأكبر من 5 مع ارتفاع ALT هي تحديدًا مزيج الأدلة الذي تعتمد عليه القاعدة LIVER_R001 لدعم نمط الإصابة الكبدية الخلوية — ويختلف عن النمط الركودي الصفراوي (R أقل من 2) أو النمط المختلط (R بين 2 و5).',
                ],
            ),
            new CaseSpecificTemplate(
                id: 'liver-cs-r007',
                version: 1,
                category: ReportTestCategory::LiverFunction,
                ruleCode: 'LIVER_R007',
                conclusionCode: 'isolated_alp_elevation',
                questionText: [
                    'en' => "This report's {label} was {value} {unit}, flagged as high, while GGT, ALT, and AST were normal or not part of the trigger. Which pattern does this isolated finding support?",
                    'ar' => 'كانت {label} في هذا التقرير {value} {unit} وصُنّفت مرتفعة، بينما كانت GGT وALT وAST طبيعية أو غير مشمولة في المُحفِّز. ما النمط الذي يدعمه هذا الاكتشاف المعزول؟',
                ],
                options: [
                    'a' => ['en' => 'An isolated ALP elevation', 'ar' => 'ارتفاع معزول في ALP'],
                    'b' => ['en' => 'A hepatocellular injury pattern', 'ar' => 'نمط إصابة كبدية خلوية'],
                    'c' => ['en' => 'A combined synthetic function signal', 'ar' => 'مؤشر مركب لوظيفة كبدية تصنيعية'],
                    'd' => ['en' => 'A prolonged PT pattern', 'ar' => 'نمط إطالة زمن البروثرومبين'],
                ],
                correctOptionId: 'a',
                explanation: [
                    'en' => 'Rule LIVER_R007 fires specifically when ALP is high while GGT/ALT/AST do not accompany it — an isolated ALP elevation, distinct from the ALP+GGT combination that instead supports a cholestatic pattern.',
                    'ar' => 'تُفعَّل القاعدة LIVER_R007 تحديدًا عندما يكون ALP مرتفعًا دون ترافقه بارتفاع GGT/ALT/AST — أي ارتفاع معزول في ALP، ويختلف عن اجتماع ALP مع GGT الذي يدعم بدلًا من ذلك نمطًا ركوديًا صفراويًا.',
                ],
            ),
        ];
    }
}
