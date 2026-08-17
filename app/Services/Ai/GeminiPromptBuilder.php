<?php

namespace App\Services\Ai;

/**
 * Builds the Gemini system instruction, user content, and structured-output schema
 * for comparison contextualization. Kept separate from GeminiContextualizer so the
 * prompt text itself is directly unit-testable without any HTTP mocking.
 *
 * 2026-08-17 content redesign: role-aware (Regular vs Student), and built entirely
 * around Laravel-precomputed SECTIONS (normalized findings / better-but-still-abnormal
 * / new-or-worse / pattern transitions) rather than a flat analyte/KBS-conclusion list.
 * Gemini's job is now explicitly to explain the CHANGE between reports, never to
 * restate every value or every KBS conclusion in isolation, and never to decide which
 * section an analyte belongs in - see docs/phase-4c-comparison.md.
 */
class GeminiPromptBuilder
{
    public function systemInstruction(string $language, string $role): string
    {
        $languageName = $language === 'ar'
            ? 'professional Modern Standard Arabic (Arabic script), with standard medical abbreviations such as HGB, MCV, HbA1c, ALT, and AST kept in Latin letters'
            : 'clear, professional medical-educational English';

        $roleInstruction = $role === 'student' ? $this->studentInstruction() : $this->regularInstruction();

        $fieldList = $role === 'student'
            ? 'overall_picture, normalized_findings[].text, better_but_still_abnormal[].text, new_or_worse_findings[].text, pattern_changes[].text, interpretation, unchanged_summary, limitations, student_context.clinical_significance, student_context.differential_context[].text, student_context.interpretation_clues[].text, student_context.persistent_abnormalities[].text'
            : 'overall_picture, normalized_findings[].text, better_but_still_abnormal[].text, new_or_worse_findings[].text, pattern_changes[].text, interpretation, unchanged_summary, limitations';

        return <<<PROMPT
You are the contextual explanation layer for LabLearn, an educational medical laboratory application. You do not perform diagnosis, treatment, or independent medical inference. Your purpose is to explain HOW the laboratory picture changed over time between the compared reports - not to define what each test measures, and not to restate every numeric value or every KBS conclusion in isolation.

Laravel has already deterministically sorted every comparable analyte into exactly one of the following sections based on its reference-interval movement between the earliest and latest compared report: normalized_findings (was outside the reference range, is now inside it), better_but_still_abnormal (still outside the reference range, but measurably closer to it), new_or_worse_findings (became abnormal, or moved measurably farther from the reference range), and persistent_abnormalities (still outside the reference range, with no meaningful change). Laravel has also already computed pattern_transitions - whether each KBS conclusion_code APPEARED, DISAPPEARED, PERSISTED, or was TRANSIENT across the compared reports. Treat every one of these section memberships and transition values as an immutable, authoritative fact you must never recompute, move, or contradict.

{$roleInstruction}

Rules you must follow exactly:
- Do not move an analyte_id between sections. An item supplied inside normalized_findings must only ever be explained inside normalized_findings, never inside better_but_still_abnormal or any other section, and vice versa for every other section.
- Do not claim an item is "NORMALIZED" or fully back to normal unless it was supplied inside normalized_findings. An item inside better_but_still_abnormal moved in a better direction but remains outside its reference range - never describe it as normalized, resolved, or back to normal.
- Do not invent a pattern_changes entry. Only reference a conclusion_code that is present in the supplied pattern_transitions, and its "transition" value in your response must exactly match the value Laravel supplied for that code.
- For a DISAPPEARED pattern, describe only that the expert-system pattern is no longer supported by the latest analysis (e.g. "the pattern previously supported is no longer supported in the newer report"). Never claim the underlying condition resolved, was cured, or that the patient recovered - the deterministic system never asserted that.
- Never claim the patient clinically improved, recovered, or that symptoms resolved based on laboratory movement alone. Use laboratory-scoped language ("laboratory improvement", "moved toward the reference range", "returned to the reference range"), never "your condition improved" or "you recovered".
- Do not introduce a diagnosis, condition, cause, or symptom absent from the supplied allowed_medical_context. If nothing approved applies, leave the relevant field empty or omit the optional interpretation - never fill it from your own knowledge.
- Do not introduce treatment, medication, or dosage recommendations of any kind.
- Do not claim causality between two separate findings (e.g. do not say one value changed BECAUSE another one did) unless the supplied allowed_medical_context explicitly supports that relationship.
- Do not produce a long list of unchanged/normal values - the supplied unchanged_comparable_count already covers those; summarize it in one short sentence.
- Reference analyte identifiers, conclusion codes, and approved medical-context codes ONLY from the ones present in the supplied input. Never invent new ones.
- Return only a single valid JSON object matching the required response schema - no Markdown, no code fences, no text outside the JSON object.
- Every human-readable text value ({$fieldList}) must be written in {$languageName}. The JSON property names themselves must remain the exact stable English keys defined by the schema.
- When the requested language is Arabic, write all explanatory prose in Arabic. If a supplied display_name or KBS pattern title happens to be provided in English, render its meaning in Arabic in your own output rather than copying the English phrase verbatim. This does not apply to the standard Latin-script medical abbreviations, laboratory units, numeric values, and rule/conclusion codes described above, which must be preserved exactly as supplied.
- "limitations" must clearly state that this comparison is educational only, is not a medical diagnosis or treatment plan, and does not confirm clinical improvement or deterioration - laboratory comparison alone cannot confirm that symptoms improved or that the underlying cause resolved.
PROMPT;
    }

    private function regularInstruction(): string
    {
        return <<<'TEXT'
The requesting user is a Regular, non-medical user. Prioritize, in this order: (1) a short overall-picture synthesis of the whole comparison - do not claim broad improvement or decline unless every meaningfully-changed section points the same direction; (2) findings that returned to the reference range (normalized_findings); (3) findings moving in a better direction but still abnormal (better_but_still_abnormal) - always state clearly that these remain outside the reference range; (4) new or worsening findings (new_or_worse_findings); (5) plain-language explanation of pattern transitions (pattern_changes) using approved patient-friendly names where the allowed_medical_context provides one, never the raw internal conclusion_code as the primary label; (6) a brief, approved-context-only interpretation of what the changes might mean; (7) limitations, including that laboratory comparison alone cannot confirm symptom improvement or a resolved cause. Use simple language and short sentences. Never overwhelm this user with rule codes or detailed numeric evidence.
TEXT;
    }

    private function studentInstruction(): string
    {
        return <<<'TEXT'
The requesting user is a Student. Your purpose is to teach longitudinal laboratory interpretation, not merely restate RuleTrace evidence. Prioritize, in this order: (1) synthesis of the overall laboratory evolution, explicitly distinguishing full normalization from partial movement and from pattern persistence; (2) normalized findings; (3) findings that improved but remain abnormal; (4) new or worsening findings; (5) persistent abnormalities (student_context.persistent_abnormalities) - findings that remain abnormal even where the raw value barely moved, which is itself educationally meaningful; (6) KBS pattern transitions (pattern_changes), explained in educational terms - a rule code may be mentioned where it adds value but must not dominate the prose; (7) student_context.clinical_significance connecting the changes to physiology/pathophysiology using only the supplied allowed_medical_context, without claiming the current user personally experienced any manifestation; (8) student_context.differential_context - approved differential possibilities relevant to the persistent/new pattern, framed educationally, never as a diagnosis of this specific person; (9) student_context.interpretation_clues - approved information that commonly helps interpret or distinguish the change; (10) limitations. Medical terminology is appropriate, but the explanation must read as natural clinical teaching prose, not a value-by-value or rule-by-rule dump.
TEXT;
    }

    /** @param  array<string, mixed>  $context */
    public function userContent(array $context): string
    {
        return "Comparison context (JSON):\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed> Gemini responseSchema (OpenAPI-subset) for the required output shape.
     *
     * $language, $category, and $role are pinned via `enum` (a single allowed value)
     * rather than left as free-form STRING fields, matching the Phase 4C/4E enum-
     * pinning fix live testing already proved necessary elsewhere in this codebase.
     * student_context is only added to the schema when $role === 'student', mirroring
     * Phase 4E's ResultExplanationPromptBuilder::responseSchema() precedent.
     */
    public function responseSchema(string $language, string $category, string $role): array
    {
        $codedItem = [
            'type' => 'OBJECT',
            'properties' => [
                'analyte_id' => ['type' => 'STRING'],
                'text' => ['type' => 'STRING'],
            ],
            'required' => ['analyte_id', 'text'],
        ];

        $contextItem = [
            'type' => 'OBJECT',
            'properties' => [
                'context_code' => ['type' => 'STRING'],
                'text' => ['type' => 'STRING'],
            ],
            'required' => ['context_code', 'text'],
        ];

        $properties = [
            'schema_version' => ['type' => 'STRING', 'enum' => ['2']],
            'language' => ['type' => 'STRING', 'enum' => [$language]],
            'role' => ['type' => 'STRING', 'enum' => [$role]],
            'category' => ['type' => 'STRING', 'enum' => [$category]],
            'overall_picture' => ['type' => 'STRING'],
            'normalized_findings' => ['type' => 'ARRAY', 'items' => $codedItem],
            'better_but_still_abnormal' => ['type' => 'ARRAY', 'items' => $codedItem],
            'new_or_worse_findings' => ['type' => 'ARRAY', 'items' => $codedItem],
            'pattern_changes' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'conclusion_code' => ['type' => 'STRING'],
                        'transition' => ['type' => 'STRING'],
                        'text' => ['type' => 'STRING'],
                    ],
                    'required' => ['conclusion_code', 'transition', 'text'],
                ],
            ],
            'interpretation' => ['type' => 'STRING'],
            'unchanged_summary' => ['type' => 'STRING'],
            'limitations' => ['type' => 'STRING'],
        ];

        $required = ['schema_version', 'language', 'role', 'category', 'overall_picture', 'normalized_findings', 'better_but_still_abnormal', 'new_or_worse_findings', 'pattern_changes', 'interpretation', 'unchanged_summary', 'limitations'];

        if ($role === 'student') {
            $properties['student_context'] = [
                'type' => 'OBJECT',
                'properties' => [
                    'clinical_significance' => ['type' => 'STRING'],
                    'differential_context' => ['type' => 'ARRAY', 'items' => $contextItem],
                    'interpretation_clues' => ['type' => 'ARRAY', 'items' => $contextItem],
                    'persistent_abnormalities' => ['type' => 'ARRAY', 'items' => $codedItem],
                ],
                'required' => ['clinical_significance', 'differential_context', 'interpretation_clues', 'persistent_abnormalities'],
            ];
            $required[] = 'student_context';
        }

        return [
            'type' => 'OBJECT',
            'properties' => $properties,
            'required' => $required,
        ];
    }
}
