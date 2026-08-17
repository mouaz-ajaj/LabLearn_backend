<?php

namespace App\Services\Ai;

use App\Models\Analysis;
use App\Services\Ai\MedicalContext\ApprovedMedicalContextResolver;

/**
 * Deterministic, bilingual, role-aware explanation built purely from the Analysis's
 * own already-localized facts and the reviewed ApprovedMedicalContextCatalog - no
 * Gemini call, no medical interpretation attempted, no new claim invented. Produces
 * the exact same content schema (schema_version "2") a validated Gemini response
 * does, so the frontend renders both identically. Used whenever Gemini is disabled,
 * unreachable, or its output fails validation - the result explanation always
 * succeeds regardless.
 *
 * 2026-08-17 content redesign: unlike the old fallback (which only echoed KBS
 * title/summary text), this fallback now renders the SAME useful patient-friendly/
 * clinical-teaching content Gemini would - possible causes, symptoms, next steps, red
 * flags, and (for Student) pathophysiology/differential/distinguishing information -
 * all copied verbatim from the reviewed catalog, never synthesized or paraphrased
 * (that synthesis is Gemini's job when available; the fallback's job is to never
 * regress to a blank or unhelpful card when Gemini is unavailable).
 */
class ResultExplanationFallbackFormatter
{
    public function __construct(private readonly ApprovedMedicalContextResolver $medicalContextResolver) {}

    public function format(Analysis $analysis, string $role, string $language): array
    {
        $groups = $this->medicalContextResolver->buildLocalizedContext($analysis, $language);

        $content = [
            'schema_version' => '2',
            'language' => $language,
            'category' => $analysis->report_category,
            'role' => $role,
            'overview' => $this->overview($analysis, $groups, $role, $language),
            'possible_causes' => $this->flattenCauses($groups),
            'possible_symptoms' => $this->flattenCodedItems($groups, 'possible_symptoms'),
            'clinical_relevance' => $this->clinicalRelevance($groups, $role, $language),
            'next_steps' => $this->flattenCodedItems($groups, 'next_steps'),
            'red_flags' => $this->flattenCodedItems($groups, 'red_flags'),
            'limitations' => $this->limitations($language),
        ];

        if ($role === 'student') {
            $content['student_context'] = $this->studentContext($groups, $language);
        }

        return $content;
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function overview(Analysis $analysis, array $groups, string $role, string $language): string
    {
        if ($groups === []) {
            // No approved catalog coverage for any fired conclusion (an honest gap,
            // never fabricated) - fall back to the analysis's own already-localized
            // summary, then a count-based generic sentence.
            $summary = $this->localized($analysis->summary_json ?? [], $language);
            if ($summary !== '') {
                return $summary;
            }
            $count = $analysis->conclusions->count();
            if ($language === 'ar') {
                return $count > 0
                    ? "حدد النظام الخبير {$count} ملاحظة تعليمية استنادًا إلى قيمك المعتمدة."
                    : 'لم يحدد النظام الخبير أي نمط مخبري ملحوظ استنادًا إلى قيمك المعتمدة.';
            }

            return $count > 0
                ? "The expert system identified {$count} educational finding(s) based on your verified values."
                : 'The expert system did not identify a notable laboratory pattern based on your verified values.';
        }

        $primary = $groups[0];
        $meaning = $role === 'student' && $primary['clinical_relevance'] !== ''
            ? $primary['clinical_relevance']
            : $primary['patient_friendly_meaning'];

        if (count($groups) > 1) {
            $meaning .= ' '.($language === 'ar'
                ? 'كما لوحظت نتائج إضافية أدناه.'
                : 'Additional findings were also noted below.');
        }

        return trim($meaning);
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function clinicalRelevance(array $groups, string $role, string $language): string
    {
        if ($groups === []) {
            return '';
        }
        $primary = $groups[0];
        if ($role === 'student') {
            return (string) $primary['clinical_relevance'];
        }

        // Regular's clinical_relevance stays brief - the overview already carries
        // the primary "what might this mean" explanation for this role.
        return $primary['clinical_relevance'] !== '' ? (string) $primary['clinical_relevance'] : '';
    }

    /** @param array<int, array<string, mixed>> $groups
     * @return array<int, array{context_code:string,title:string,explanation:string}>
     */
    private function flattenCauses(array $groups): array
    {
        $seen = [];
        $result = [];
        foreach ($groups as $group) {
            foreach ($group['possible_causes'] ?? [] as $cause) {
                if (isset($seen[$cause['code']])) {
                    continue;
                }
                $seen[$cause['code']] = true;
                $result[] = [
                    'context_code' => $cause['code'],
                    'title' => $cause['text'],
                    'explanation' => $cause['description'] ?? '',
                ];
            }
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $groups
     * @return array<int, array{context_code:string,text:string}>
     */
    private function flattenCodedItems(array $groups, string $field): array
    {
        $seen = [];
        $result = [];
        foreach ($groups as $group) {
            foreach ($group[$field] ?? [] as $item) {
                if (isset($seen[$item['code']])) {
                    continue;
                }
                $seen[$item['code']] = true;
                $result[] = ['context_code' => $item['code'], 'text' => $item['text']];
            }
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function studentContext(array $groups, string $language): array
    {
        $pathophysiologyParts = [];
        $differential = [];
        $distinguishing = [];
        $learningParts = [];
        $seenDifferential = [];
        $seenDistinguishing = [];

        foreach ($groups as $group) {
            $studentContext = $group['student_context'] ?? null;
            if ($studentContext === null) {
                continue;
            }
            if (($studentContext['pathophysiology'] ?? '') !== '') {
                $pathophysiologyParts[] = $studentContext['pathophysiology'];
            }
            foreach ($studentContext['differential_considerations'] ?? [] as $item) {
                if (isset($seenDifferential[$item['code']])) {
                    continue;
                }
                $seenDifferential[$item['code']] = true;
                $differential[] = ['context_code' => $item['code'], 'text' => $item['text']];
            }
            foreach ($studentContext['distinguishing_information'] ?? [] as $item) {
                if (isset($seenDistinguishing[$item['code']])) {
                    continue;
                }
                $seenDistinguishing[$item['code']] = true;
                $distinguishing[] = ['context_code' => $item['code'], 'text' => $item['text']];
            }
            foreach ($studentContext['learning_points'] ?? [] as $item) {
                $learningParts[] = $item['text'];
            }
        }

        return [
            'pathophysiology' => implode(' ', $pathophysiologyParts),
            'differential_considerations' => $differential,
            'distinguishing_information' => $distinguishing,
            'learning_takeaway' => $learningParts === []
                ? ($language === 'ar'
                    ? 'راجع كيف ترتبط النتائج المخبرية أعلاه بعضها ببعض، وقارنها بالمجال المرجعي المرفق مع كل قيمة.'
                    : 'Review how the laboratory findings above relate to one another, and compare each to its supplied reference interval.')
                : implode(' ', $learningParts),
        ];
    }

    private function limitations(string $language): string
    {
        return $language === 'ar'
            ? 'هذا الشرح تعليمي فقط ولا يمثل تشخيصًا طبيًا أو خطة علاج أو تأكيدًا لتحسّن أو تدهور الحالة السريرية. يُرجى استشارة مختص طبي مؤهل لتفسير النتائج.'
            : 'This explanation is educational only and does not represent a medical diagnosis, a treatment plan, or a confirmation of clinical improvement or deterioration. Consult a qualified healthcare professional for medical interpretation.';
    }

    /** @param array<string, mixed> $localizedText */
    private function localized(array $localizedText, string $language): string
    {
        $value = $language === 'ar' ? ($localizedText['ar'] ?? null) : ($localizedText['en'] ?? null);

        return is_string($value) && $value !== '' ? $value : (string) ($localizedText['en'] ?? '');
    }
}
