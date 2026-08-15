<?php

return [
    // Preferred MVP composition ("Dynamic Quiz Size Policy"): a product target, not a
    // hard contract. A session's actual_total/actual_general_count/actual_case_specific_count
    // are always derived from what was really selected/available - callers must never
    // assume actual_total equals these preferred values.
    'preferred_general_count' => (int) env('QUIZ_PREFERRED_GENERAL_COUNT', 14),
    'preferred_case_specific_count' => (int) env('QUIZ_PREFERRED_CASE_SPECIFIC_COUNT', 6),

    // General Question Bank Generator (Phase 3B.4) - see backend/docs/general-question-bank.md.
    // Absolute/relative path to the KBS knowledge_base directory the generator reads
    // JSON from directly (never over HTTP - unlike Case-Specific generation, this never
    // calls the live KBS service). Sibling repo layout: project-root/{backend,kbs}.
    'kbs_knowledge_base_path' => env('QUIZ_KBS_KNOWLEDGE_BASE_PATH', base_path('../kbs/knowledge_base')),

    // Bumped whenever template wording/logic changes in a way that should be reflected
    // in regenerated stable_source_key/generator_version metadata. Not tied to app releases.
    'general_question_generator_version' => 'v1',

    // Safety gate for `php artisan quiz:refresh-general-bank` (Step: Development vs
    // Production Safety). false by default: the command no-ops unless this is true OR
    // `--force` is passed explicitly. start-lablearn.ps1 sets
    // QUIZ_REFRESH_GENERAL_BANK_ON_START=true in the dev backend .env on every launch;
    // production environments should leave this unset/false so a process restart never
    // silently rebuilds question content.
    'refresh_general_bank_on_start' => (bool) env('QUIZ_REFRESH_GENERAL_BANK_ON_START', false),

    // Opt-in filter (Step: Review Status). false in development so the generated,
    // not-yet-medically-reviewed bank can actually be used. A future production
    // deployment can flip this to true once a real review workflow marks questions
    // APPROVED, without any code change here.
    'require_approved_general_questions' => (bool) env('QUIZ_REQUIRE_APPROVED_GENERAL_QUESTIONS', false),
];
