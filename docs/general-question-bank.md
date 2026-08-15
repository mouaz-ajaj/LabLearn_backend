# KBS-Driven General Question Bank Generator (Phase 3B.4)

This document is the operational reference for the General Question Bank generator: a deterministic, template-driven system that transforms the KBS's structured medical knowledge (panels, analytes, aliases, active rules, derived values, classifiers) into a large, reusable, bilingual General MCQ bank for the Student Quiz — replacing the Phase 3B.2 `[DEV FIXTURE]` placeholders. Case-Specific generation (Phase 3B.3) is a separate, unmodified system.

## Why the KBS, not a ready-made Question Bank

The KBS is not a question bank. `kbs/knowledge_base/` contains medical *knowledge* — which analytes exist, which panel they belong to, which are required, what active rules connect them, what a rule's conclusion means — not pre-written quiz content. A separate, pre-existing subsystem (`kbs/core/questions.py`, `kbs/knowledge_base/question_blueprints.json`) *does* generate a Python-side question bank, but it was deliberately **not used as this generator's data source**: it is not exposed by any KBS API route (confirmed — `kbs/api/app.py` only exposes `/health`, `/v1/metadata`, `/v1/analyze`, `/v1/validate`), its content is Arabic-only (no `question_en`), and its MCQs use 3 options, not 4. This generator instead reads the same raw structured files `kbs/core/loader.py` reads (`tests.json`, `liver_tests.json`, `panels.json`, `rules.json`, `expanded_rules.json`, `liver_rules.json`, `expanded_liver_rules.json`, `analyte_disambiguation.json`), and `kbs/core/classifier.py`/`derived_values.py`/`liver_engine.py` for classification and derivation logic — transcribed into PHP, never called over HTTP.

## Architecture

```text
KBS knowledge_base/*.json (read directly from disk, never over HTTP)
    -> KbsKnowledgeBase (app/Services/Quiz/GeneralQuestions/Kbs/)
       analytes, panels, required inputs, active rules (regular + liver), cross-panel links
    -> 13 GeneralQuestionTemplateFamily implementations
       (app/Services/Quiz/GeneralQuestions/TemplateFamilies/)
    -> GeneratedGeneralQuestion value objects
    -> GeneralQuestionValidator (per-question, then bank-wide)
    -> GeneralQuestionGenerator (orchestrator: parse -> generate -> validate -> dedupe)
    -> php artisan quiz:refresh-general-bank (the only place that touches the database)
    -> questions table (source=KBS_GENERATED)
    -> FinalizeQuizSession selects up to 14 active General questions per category (unchanged)
```

This is architecturally separate from Case-Specific generation:

```text
General:        KBS structured knowledge  -> template families -> questions table -> quiz selects up to 14
Case-Specific:  current Verified Report -> KBS Analysis -> fired RuleTrace -> CaseSpecificQuestionBuilder -> snapshot
```

Nothing in `CaseSpecificQuestionBuilder`/`CaseSpecificTemplateCatalog`/`FinalizeQuizSession`'s Case-Specific path was changed.

## KBS knowledge extraction (`app/Services/Quiz/GeneralQuestions/Kbs/`)

- `KbsKnowledgeBase::load()` reads the JSON files, merges `tests.json` + `liver_tests.json` into one analyte pool (mirroring `loader.py`), and resolves `panels.json`'s `required`/`tests` lists.
- `KbsAnalyte` — id, category, panel, official-panel-membership flag, name/name_ar, short_name, `safeAliases` (aliases minus the short name, the full name, and anything flagged ambiguous by `analyte_disambiguation.json`'s `base_aliases` — e.g. bare "Neutrophils" is excluded because it could mean `neutrophils_percent` or `anc`), `derived`/`formula`, `classifier`.
- `KbsRule` — one **active** rule (`rules.json`/`expanded_rules.json`, or `liver_rules.json`/`expanded_liver_rules.json` via `LiverRuleTriggerCatalog`), reduced to `jointTriggers`: the (analyte, status) pairs that are genuinely *all* required together. A `when.any` clause (or a liver rule whose real Python condition is OR-shaped) is **not** flattened into `jointTriggers` — an "any one of several" rule has no single honest "combination" to describe, so it simply produces no Pattern/Rule-Input/Conclusion-Matching question, rather than one that overstates specificity. An `any` clause whose `min_matched` equals its own size (e.g. R019/R021: 2 of 2 required) is logically an `all` and *is* included.
- `LiverRuleTriggerCatalog` — liver rules are evaluated by hand-written Python (`core/liver_engine.py`), not the declarative `when` engine, so there is no JSON to parse triggers from. This is a manual, line-by-line transcription of that file's actual `if`/`elif` conditions. 14 of 21 active liver rules are representable as a clean AND-combination; 7 are explicitly excluded with a documented reason (`LiverRuleTriggerCatalog::SKIPPED_LOGIC_KEYS`) because they are genuinely OR-shaped (e.g. "albumin low OR INR high"), fraction/ratio-based rather than a plain status (e.g. direct-bilirubin predominance), or a pure data-quality threshold (e.g. "fewer than two markers present") — representing any of these as a definite required combination would misstate how the rule actually fires.
- `ConditionPhraseRenderer` — deterministically renders a natural bilingual sentence from a set of trigger pairs (e.g. "Low Hemoglobin together with low MCV" / "انخفاض الهيموغلوبين مع انخفاض متوسط حجم الكرية"). Arabic uses an idafa (noun-construct) form — "ارتفاع {label}" ("a rise in {label}"), not an adjective — specifically because an adjective would need to agree in gender with the analyte name (feminine "نسبة" vs. masculine "عدد"), which one fixed word cannot do correctly for every analyte; a noun-construct head does not need to agree, so it reads correctly regardless.
- `ConditionNameCatalog` — bilingual display name per `condition_id`. English is derived from the id itself (snake_case -> Title Case, with known lab abbreviations like ALT/AST/ALP/GGT kept upper-case even mid-title). Arabic is hand-authored for every condition_id this generator actually draws from (~51 entries) — the same authoring approach already used for the Phase 3B.3 Case-Specific templates, since the KBS's own `conditions.json`/`liver_conditions.json` Arabic content is incomplete/inconsistent (confirmed during Phase 3B.3).

## Template Families implemented (13)

All in `app/Services/Quiz/GeneralQuestions/TemplateFamilies/`, `code()` value in parentheses:

1. **PanelMembershipFamily** (`PANEL_MEMBERSHIP`) — "Which of the following belongs to the {category} panel?" from `panels.json`'s own `tests[]`.
2. **AbbreviationFamily** (`ABBREVIATION`) — both directions (short_name -> name, name -> short_name).
3. **AliasRecognitionFamily** (`ALIAS_RECOGNITION`) — from `safeAliases` only.
4. **RequiredInputsFamily** (`REQUIRED_INPUTS`) — from `panels.json`'s `required[]`. Skipped for DIABETES (empty `required[]` — no required/optional distinction exists there currently).
5. **OptionalSupportingInputsFamily** (`OPTIONAL_SUPPORTING_INPUTS`) — inverse of #4, same DIABETES skip.
6. **RuleInputRecognitionFamily** (`RULE_INPUT_RECOGNITION`) — "which pair of analytes" for rules with exactly 2 joint analytes (`KbsRule::isCleanPair()`). Distractors keep the correct rule's first analyte and vary the second, excluding any second analyte that would *also* form a genuinely valid pair for another active rule.
7. **PatternConditionRecognitionFamily** (`PATTERN_CONDITION_RECOGNITION`) — pattern name -> findings direction, any rule with non-empty `jointTriggers` (not just pairs).
8. **StatusClassificationFamily** (`STATUS_CLASSIFICATION`) — reachable statuses transcribed directly from `classifier.py`'s real classifier functions (e.g. `random_glucose` never returns "normal"/"prediabetes"; `hba1c` never returns "low"). Distractors are statuses reachable *somewhere* in the KBS but never for this analyte's own classifier.
9. **DerivedValueInputsFamily** (`DERIVED_VALUE_INPUTS`) — the two real inputs, parsed from `tests.json`'s own `formula` string (ANC/ALC/AEC/absolute_monocytes/absolute_basophils) or transcribed from `derived_values.py`'s bilirubin branch (`indirect_bilirubin`).
10. **CrossPanelRelationshipFamily** (`CROSS_PANEL_RELATIONSHIP`) — only fires when `KbsKnowledgeBase::crossPanelAnalyteIds()` finds a rule referencing an analyte from a different category (currently exactly one: `GLUX_R001` reads CBC's hemoglobin as DIABETES-pattern context).
11. **RuleConclusionMatchingFamily** (`RULE_CONCLUSION_MATCHING`) — the reverse of #7 (findings -> pattern name).
12. **MissingSupportingInformationFamily** (`MISSING_SUPPORTING_INFORMATION`) — deliberately narrow: the KBS's only clean example is `LIVERX_R005` (ALT/AST high but ALP unavailable prevents R-value classification), read directly since that rule is itself excluded from `LiverRuleTriggerCatalog`.
13. **CategoryComparisonFamily** (`CATEGORY_COMPARISON`) — "which pair both belong to {category}" — deliberately a *pairwise* variant of #1 (not the same question shape) so the two families test distinct recall, not the same fact twice.

### Families considered but not implemented separately

- **"Derived Value Meaning/Relationship"** (originally sketched as family 10) — folded into `DerivedValueInputsFamily`. Both would draw from the identical `formula` data and would produce near-duplicate questions about the same WBC-differential relationship.
- **"Same-Panel Relationship"** (originally sketched as family 11) — folded into `RuleInputRecognitionFamily`. Given the KBS's declarative rule structure, both would draw from the identical pool of 2-analyte composite active rules.

13 distinct, non-overlapping families were implemented — within the requested 10–15 range.

## Distractor strategy

Distractors are drawn from real KBS entities of the same "kind" as the correct answer (other analytes in the same category, other rules' pattern names, other reachable-elsewhere statuses, ...), never generic "Option B/C/D" text. Selection is via `DeterministicSelector::pick()` — candidates ranked by `md5(seed|candidate)` — never PHP's `array_rand()`/`shuffle()`, so the same input always produces the same distractor set (required for the Determinism guarantee). Family-specific care is taken so a distractor cannot also be defensibly correct: `RuleInputRecognitionFamily` excludes any second analyte that forms another real rule pair; `PatternConditionRecognitionFamily`/`RuleConclusionMatchingFamily` dedupe the candidate pool by `condition_id` first (`PicksDistinctConditionRules::distinctByConditionId()`) so two different rules that happen to share a condition_id never appear as two different options in the same question.

## Bilingual generation

Every question/option/explanation is an authored `{en, ar}` pair (question stems and explanations are hand-written per family, matching the Phase 3B.3 Case-Specific template precedent), with the *specific facts* — which analyte, which rule, which status — filled in from real KBS data via `strtr`/interpolation, never machine-translated word-by-word. Medical abbreviations (`HGB`, `MCV`, `HbA1c`, `ALT`, ...) are stored once and reused identically in both languages (no translation, no LTR/RTL mirroring risk).

## Explanation quality

Every explanation states the concrete KBS-grounded reason (e.g. *"The KBS configuration lists {short_name} as the short name for {name}"*, *"Rule {code} requires {X} together with {Y} — {condition}"*) — never "the answer is A". `GeneralQuestionValidator` enforces a minimum length (15 chars) as a floor against degenerate explanations.

## Source traceability

`questions` gained six nullable columns (migration `2026_08_15_000001_...`): `source` (`KBS_GENERATED` for this pipeline, `null` for anything else — manual/factory questions), `source_type` (`ANALYTE`|`PANEL`|`RULE`|`DERIVED_VALUE`|`CLASSIFICATION`|`RELATIONSHIP`), `source_id` (e.g. `mcv`, `R002`, `CBC`), `template_family`, `generator_version`, and `stable_source_key` (unique-indexed).

### Stable source keys

Format: `{CATEGORY}:{discriminator...}:{TEMPLATE_FAMILY}:{generator_version}`, e.g. `CBC:mcv:PANEL_MEMBERSHIP:v1`, `CBC:R002:RULE_INPUT_RECOGNITION:v1`, `LIVER_FUNCTION:LIVERX_R005:MISSING_SUPPORTING_INFORMATION:v1`. Deterministic (built only from stable KBS identifiers, never a counter or timestamp), unique (bank-wide validation hard-fails on any collision), versioned (bumping `config('quiz.general_question_generator_version')` changes every key at once, so old and new wording never collide).

## Duplicate / near-duplicate prevention

Two layers: (1) exact `stable_source_key` collision (impossible by construction across families, checked anyway as a safety net) and (2) `GeneratedGeneralQuestion::normalizedTextKey()` — a normalized `(category, question stem, correct-answer text)` triple. The correct-answer text is included deliberately: several families (Panel Membership, Required Inputs, Category Comparison, ...) intentionally reuse one identical stem per category and vary only the options — without the answer in the key, every one of those would look like the same "duplicate" question. A small number of individually-malformed candidates (2 out of ~366 in the current bank — see below) are dropped per-question by `GeneralQuestionValidator::validateQuestion()`; `GeneralQuestionValidator::validateBank()` is a separate, bank-wide safety net (minimum 14/category, no duplicate keys) that hard-fails the whole refresh, never silently drops bank-level problems.

## Review status

Generated questions are stored as `QuestionReviewStatus::GeneratedPendingReview` (`GENERATED_PENDING_REVIEW`) — a new, honest enum case (the existing `Draft`/`Approved` cases are unchanged and still used by manually-authored/factory content). They are **not** claimed as medically reviewed. `FinalizeQuizSession`'s General-question query gained one `->when(...)` clause: when `config('quiz.require_approved_general_questions')` is true, only `Approved` questions are selected. It defaults to `false` (env `QUIZ_REQUIRE_APPROVED_GENERAL_QUESTIONS`), so the generated bank participates in quizzes today; a future production deployment can flip it to `true` once a real review workflow marks specific questions `Approved`, with no further code change.

## The refresh command

```bash
php artisan quiz:refresh-general-bank [--force]
```

1. Generate the full bank in memory (`GeneralQuestionGenerator`).
2. Validate the whole bank (`GeneralQuestionValidator::validateBank`) — **before any database write**. On failure: print the errors, exit non-zero, database untouched.
3. Print the summary table (per-category counts, total, dropped-candidate counts, skipped liver rule codes).
4. Inside one DB transaction: delete existing `source = 'KBS_GENERATED'` rows, delete rows whose `question_text_json->en` starts with `[DEV FIXTURE]` (the exact marker `QuizQuestionBankDevSeeder` uses), insert the new bank. Any exception rolls the whole transaction back — the previous, working bank is left exactly as it was.
5. Print old-removed / dev-fixture-removed / new-stored counts, then before/after row counts for `quiz_sessions`, `quiz_question_snapshots`, `student_answers` (always 0 changed — the command never touches them; a non-zero delta throws, since that would mean something *other* than this command wrote to them mid-run).

Manually: run the command above from `backend/` any time the KBS knowledge or template catalog changes (pass `--force` outside the dev launcher, since the safety gate — see below — defaults closed).

## `start-lablearn.ps1` integration

Inserted immediately after `php artisan migrate --force` and before any service starts (`kbs`'s JSON files are read straight off disk, so no service — not even the KBS API — needs to be up yet):

```powershell
Invoke-CheckedProcess $php @('artisan', 'quiz:refresh-general-bank') $BackendRoot 180 'Refreshing KBS-driven General Question Bank'
```

The launcher's `$backendSettings` block (which already unconditionally stamps several dev-only `.env` values on every run, e.g. `QUEUE_CONNECTION=database`) now also stamps `QUIZ_REFRESH_GENERAL_BANK_ON_START=true`. This script is exclusively a local developer tool (it starts `artisan serve`, a Streamlit debug UI, GPU checks, Expo — nothing here is a production deploy path), so it always opts in.

## Development vs. production behavior

`config('quiz.refresh_general_bank_on_start')` (env `QUIZ_REFRESH_GENERAL_BANK_ON_START`, **default `false`**) gates the command itself, independent of who calls it: without `--force`, the command checks this config and safely no-ops (exit 0, explanatory message, zero database change) if it is false. `start-lablearn.ps1` sets it true on every dev launch, so the dev workflow always refreshes. A production environment that never sets this env var — and never passes `--force` — gets a guaranteed no-op if the command is ever invoked by a deploy script or scheduler, so question content is never silently rebuilt on a process restart.

## Determinism, not randomness

Regenerating from unchanged KBS files and an unchanged `generator_version` produces the byte-identical set of `stable_source_key`s (verified by an automated test that runs the generator twice and compares). This is intentional: quiz-to-quiz variety comes from `FinalizeQuizSession` selecting a random 14-of-many subset per session (unchanged Phase 3B.2 behavior — `inRandomOrder()->limit(14)`), not from the bank itself changing wording on every server restart.

## Historical snapshot safety

The refresh command only ever writes to `questions`. `quiz_sessions`, `quiz_question_snapshots`, and `student_answers` are never referenced in `RefreshGeneralQuestionBank::handle()`. Verified by dedicated tests (`RefreshGeneralQuestionBankCommandTest`) that build a real quiz session + snapshot + answer, run the refresh, and assert the three tables are byte-identical before/after — including the specific case where the refresh deletes the *source* Question row a snapshot originally copied from (a factory-seeded, non-`KBS_GENERATED` row surviving refresh in practice; the snapshot's own frozen `question_text_json` is asserted unchanged regardless).

## Current bank size (real run, `generator_version = v1`)

| Category | Count |
| --- | --- |
| CBC | 195 |
| DIABETES | 61 |
| LIVER_FUNCTION | 104 |
| **Total** | **360** |

Well above the "significantly more than 14 per category" requirement. Not forced to exactly ~200 — the actual count reflects genuine KBS-grounded diversity (13 families x real analyte/rule counts per category), with 2 individually-invalid and 4 near-duplicate candidates dropped by validation out of ~366 raw candidates.

## Manual refresh instructions (outside the dev launcher)

```bash
cd backend
php artisan quiz:refresh-general-bank --force
```

Safe to re-run at any time (transactional, validated before write, never touches historical quiz data).
