<?php

namespace App\Services\Ai;

/**
 * Builds the Gemini system instruction, user content, and structured-output schema
 * for Phase 4E result explanation. Deliberately a separate prompt from
 * GeminiPromptBuilder (Phase 4C comparison) rather than a reused/generic one - the
 * two tasks explain different kinds of deterministic facts (a single analysis's
 * conclusions vs. a cross-report trend) and benefit from task-specific instructions.
 * Kept separate from GeminiResultExplainer so the prompt text itself is directly
 * unit-testable without any HTTP mocking, mirroring GeminiPromptBuilder's own split.
 *
 * 2026-08-17 content redesign: schema_version "2" replaces the v1 "restate every KBS
 * conclusion as an isolated essay" shape with a role-aware, patient-friendly-for-
 * Regular / clinical-teaching-for-Student shape, built entirely from the reviewed,
 * source-grounded `allowed_medical_context` catalog (see
 * App\Services\Ai\MedicalContext) - never from Gemini's own medical knowledge. See
 * backend/docs/localization-integrity-repair.md's sibling doc,
 * backend/docs/phase-4e-result-explanation.md, for the full rationale.
 */
class ResultExplanationPromptBuilder
{
    public function systemInstruction(string $language, string $role): string
    {
        $languageName = $language === 'ar'
            ? 'professional Modern Standard Arabic (Arabic script), with standard medical abbreviations such as HGB, MCV, MCH, MCHC, RDW, HbA1c, ALT, and AST kept in Latin letters'
            : 'clear, professional medical-educational English';

        $roleInstruction = $role === 'student'
            ? $this->studentInstruction()
            : $this->regularInstruction();

        $fieldList = $role === 'student'
            ? 'overview, possible_causes[].title/explanation, possible_symptoms[].text, clinical_relevance, next_steps[].text, red_flags[].text, student_context.pathophysiology, student_context.differential_considerations[].text, student_context.distinguishing_information[].text, student_context.learning_takeaway, limitations'
            : 'overview, possible_causes[].title/explanation, possible_symptoms[].text, clinical_relevance, next_steps[].text, red_flags[].text, limitations';

        return <<<PROMPT
You are the contextual explanation layer for LabLearn, an educational medical laboratory application. The supplied KBS output is already the deterministic source of medical reasoning, and the supplied "allowed_medical_context" is already the reviewed, source-grounded medical reference content. You do not perform diagnosis, treatment, or independent medical inference, and you do not draw on your own general medical knowledge for any specific medical fact - you may only organize, explain, connect, and present the supplied facts according to the user's role.

Treat every conclusion, evidence value, unit, status, fired_rule_code, and missing_information item in the supplied "analysis" as an immutable, authoritative fact. Treat every group inside "allowed_medical_context" as the ONLY source you may draw causes, symptoms, next steps, red flags, pathophysiology, differential considerations, and distinguishing information from.

{$roleInstruction}

Rules you must follow exactly:
- Do not recalculate, alter, or invent any supplied value, unit, status, or reference range.
- Do not introduce a diagnosis, condition, cause, symptom, next step, red flag, or differential possibility that is absent from the supplied "allowed_medical_context". If nothing approved applies to a given output section, return an empty array for that section - never fill it from your own knowledge, and never write a placeholder sentence like "no information is available".
- Every item you output under possible_causes, possible_symptoms, next_steps, red_flags, and (for Student) differential_considerations/distinguishing_information MUST reference the exact "code" of a supplied allowed_medical_context item via its "context_code" field. Never invent a new code and never omit context_code.
- Do not introduce treatment, medication, or dosage recommendations of any kind - not even a general one like "start taking iron" or "reduce your sugar intake as treatment".
- Do not claim the patient has clinically recovered or deteriorated based only on laboratory values.
- Do not state or imply that the user is currently experiencing any symptom - every symptom must be phrased as something that "may occur", "can sometimes be associated with", or "some people may notice", never "you are experiencing" or "your symptoms are".
- Do not rank one cause or differential possibility as most likely unless the supplied allowed_medical_context explicitly does so - present multiple possibilities neutrally (e.g. "several conditions can produce this pattern, including...").
- Synthesize RELATED findings that belong to the same allowed_medical_context group into one coherent explanation instead of one isolated paragraph per KBS conclusion code. Do not merge groups that are not related just to shorten the text, and do not invent a causal link between two separate groups unless a supplied group explicitly states one.
- Only mention a specific numeric value when it materially improves understanding of the synthesized picture - do not restate every evidence value already shown elsewhere on the result screen.
- Reference conclusion codes, analyte identifiers, and rule codes ONLY from the ones present in the supplied input. Never invent new ones.
- Return only a single valid JSON object matching the required response schema - no Markdown, no code fences, no text outside the JSON object.
- Every human-readable text value ({$fieldList}) must be written in {$languageName}. The JSON property names and every "context_code" value must remain the exact stable English identifiers defined by the schema/supplied context - never translate a context_code.
- "limitations" must clearly state that this explanation is educational only, is not a medical diagnosis or treatment plan, and does not confirm clinical improvement or deterioration.
PROMPT;
    }

    private function regularInstruction(): string
    {
        return <<<'TEXT'
The requesting user is a Regular, non-medical user. Your primary purpose is to help this user understand what the laboratory pattern may mean in practical, everyday terms - not to simply restate laboratory values or KBS rule evidence. Prioritize, in this order: (1) the overall meaning of the pattern in plain language; (2) possible approved causes or conditions that can produce this pattern; (3) possible approved symptoms that can sometimes accompany it; (4) safe, general next steps; (5) approved warning signs that may warrant faster evaluation. Use simple language and short sentences. Never diagnose. Never prescribe or suggest treatment. Never add a cause, symptom, next step, or warning sign that is not present in the supplied allowed_medical_context. Use hedged phrasing such as "may be consistent with", "the laboratory pattern may suggest", or "the expert system identified a pattern compatible with" - never "you have [condition]".
TEXT;
    }

    private function studentInstruction(): string
    {
        return <<<'TEXT'
The requesting user is a Student. Your primary purpose is educational clinical reasoning, not merely restating KBS evidence. Prioritize, in this order: (1) synthesis of the overall clinical/laboratory picture across related findings; (2) clinical significance of the pattern; (3) pathophysiology, using only what the supplied allowed_medical_context provides; (4) approved differential considerations (educational possibilities, not a diagnosis of this specific person); (5) clinical manifestations/symptoms that can occur with this pattern; (6) information that commonly helps distinguish the approved possibilities from one another; (7) how the KBS evidence and fired rule codes support the pattern, as supporting reasoning rather than the main narrative. Medical terminology is appropriate and expected, but the explanation must read as natural clinical teaching prose, not a repetition of raw evidence. Never write "the patient has X" - use educational framing such as "conditions that may produce this pattern include..." and never present distinguishing information as a personalized order such as "you need to order...".
TEXT;
    }

    /** @param  array<string, mixed>  $context */
    public function userContent(array $context): string
    {
        return "Result context (JSON):\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed> Gemini responseSchema (OpenAPI-subset) for the required output shape.
     *
     * $language, $category, and $role are pinned via `enum` (a single allowed value)
     * rather than left as free-form STRING fields, mirroring GeminiPromptBuilder's own
     * fix for the exact same real-world drift Phase 4C live-testing uncovered (Gemini
     * echoing "1.0" instead of the literal "1" when only told via prose).
     *
     * `student_context` is included in the schema (and `required`) ONLY for
     * role="student" - Regular's schema never asks Gemini for it at all, which is
     * simpler and more reliable than requesting a nullable field and validating that
     * Regular always returns null (see ResultExplanationResponseValidator).
     */
    public function responseSchema(string $language, string $category, string $role): array
    {
        $contextCodedItem = [
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
            'category' => ['type' => 'STRING', 'enum' => [$category]],
            'role' => ['type' => 'STRING', 'enum' => [$role]],
            'overview' => ['type' => 'STRING'],
            'possible_causes' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'context_code' => ['type' => 'STRING'],
                        'title' => ['type' => 'STRING'],
                        'explanation' => ['type' => 'STRING'],
                    ],
                    'required' => ['context_code', 'title', 'explanation'],
                ],
            ],
            'possible_symptoms' => ['type' => 'ARRAY', 'items' => $contextCodedItem],
            'clinical_relevance' => ['type' => 'STRING'],
            'next_steps' => ['type' => 'ARRAY', 'items' => $contextCodedItem],
            'red_flags' => ['type' => 'ARRAY', 'items' => $contextCodedItem],
            'limitations' => ['type' => 'STRING'],
        ];
        $required = [
            'schema_version', 'language', 'category', 'role', 'overview',
            'possible_causes', 'possible_symptoms', 'clinical_relevance',
            'next_steps', 'red_flags', 'limitations',
        ];

        if ($role === 'student') {
            $properties['student_context'] = [
                'type' => 'OBJECT',
                'properties' => [
                    'pathophysiology' => ['type' => 'STRING'],
                    'differential_considerations' => ['type' => 'ARRAY', 'items' => $contextCodedItem],
                    'distinguishing_information' => ['type' => 'ARRAY', 'items' => $contextCodedItem],
                    'learning_takeaway' => ['type' => 'STRING'],
                ],
                'required' => ['pathophysiology', 'differential_considerations', 'distinguishing_information', 'learning_takeaway'],
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
