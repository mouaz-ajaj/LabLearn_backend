<?php

return [
    // Phase 4C - Gemini contextualization for multi-report comparison. Gemini never
    // computes numerical facts; it only explains the deterministic comparison Laravel
    // already built. Effectively enabled only when both 'enabled' is true AND a real
    // API key is configured, so production stays safe by default with no key set.
    'gemini' => [
        // NOTE: despite the "comparison" name (kept for backward compatibility with
        // already-deployed .env files), this is the single master Gemini
        // enabled/configured gate shared by every AI feature - Phase 4C comparison
        // contextualization AND Phase 4E result explanation both call
        // GeminiClient::isConfigured(), which reads this flag. Disabling it disables
        // Gemini everywhere; see 'result_explanation.enabled' below for a narrower,
        // feature-specific gate that only affects Phase 4E.
        'enabled' => (bool) env('AI_COMPARISON_CONTEXT_ENABLED', true),
        'base_url' => env('GEMINI_SERVICE_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'model' => env('GEMINI_MODEL', 'gemini-3.7-flash'),
        'api_key' => env('GEMINI_API_KEY'),
        'timeout_seconds' => (int) env('GEMINI_SERVICE_TIMEOUT_SECONDS', 20),
        'connect_timeout_seconds' => (int) env('GEMINI_SERVICE_CONNECT_TIMEOUT_SECONDS', 5),
        'retry_attempts' => (int) env('GEMINI_SERVICE_RETRY_ATTEMPTS', 2),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 2048),
    ],

    // Phase 4E - AI-assisted, role-aware explanation of an already-completed
    // deterministic KBS Analysis. Gated by BOTH this block's 'enabled' flag AND the
    // shared 'gemini.enabled'/api_key gate above - disabling either one degrades every
    // request to the deterministic fallback formatter, never breaking the result.
    'result_explanation' => [
        'enabled' => (bool) env('AI_RESULT_EXPLANATION_ENABLED', true),
        // Bump this when the prompt/schema changes meaningfully so old cached
        // explanations are never silently reused as if they came from the new
        // prompt - see docs/phase-4e-result-explanation.md "Prompt/schema versioning".
        // v2 (2026-08-16): Arabic/English localization integrity repair - the
        // system instruction now explicitly forbids echoing an English source
        // label/title verbatim into Arabic prose, and ResultExplanationResponseValidator
        // now rejects language=ar responses whose prose fails LanguagePurityChecker.
        // Bumping this ensures no pre-repair cached explanation (which could contain
        // the confirmed English-under-ar bug) is ever served after this deploy - a
        // fresh cache row is created under the new version instead of reusing v1 rows.
        // v3 (2026-08-17): Phase 4E content redesign - the system instruction, role
        // priorities, and response schema were rewritten around the approved medical
        // context catalog (causes/symptoms/next_steps/red_flags/student_context).
        // Combined with the schema_version bump ('1' -> '2'), this guarantees no
        // pre-redesign cached row (old "important_findings" shape) is ever served.
        'prompt_version' => env('RESULT_EXPLANATION_PROMPT_VERSION', 'v3'),
    ],

    // Phase 4E content redesign (2026-08-17): the reviewed, source-grounded
    // "approved medical context" catalog Gemini is allowed to draw
    // causes/symptoms/next-steps/red-flags/student differential content from.
    // See backend/resources/medical_context/*.json (one file per KBS report
    // category) and App\Services\Ai\MedicalContext\ApprovedMedicalContextCatalog.
    // Gemini never generates this content itself - only ever presents what this
    // catalog resolves for the analysis's own KBS conclusion codes.
    'medical_context' => [
        'catalog_path' => env('AI_MEDICAL_CONTEXT_CATALOG_PATH', resource_path('medical_context')),
    ],
];
