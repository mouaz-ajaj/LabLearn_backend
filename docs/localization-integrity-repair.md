# Arabic/English Localization Integrity Repair

**Status: IMPLEMENTED (2026-08-16)**

A focused correctness repair, not new medical functionality. Follows a prior
root-cause audit that traced why Arabic result screens sometimes contained
unintended English prose. This document is the reference for the fix; the audit
itself established the diagnosis and is not repeated here.

## Root cause (confirmed, not speculative)

The English-in-Arabic bug originated entirely in `kbs/`, not in Laravel or the
frontend:

1. `kbs/knowledge_base/conditions.json` (the original/base CBC + glucose
   condition catalog — 20 conditions) had **zero** `name_ar` fields.
2. `kbs/knowledge_base/rules.json` had 19 of its 28 active rules (R001–R016,
   R031–R033 — not just R001–R017 as the audit's initial estimate suggested)
   missing `explanation_ar`.
3. `kbs/core/report_builder.py`'s `why_ar` construction did:
   ```python
   "why_ar": [item.get("explanation_ar") or item.get("explanation", "") for item in rule_details]
   ```
   When `explanation_ar` was absent, this **silently substituted the English
   `explanation`** into the Arabic-designated `why_ar` list. That list then fed
   `_localized()` in `kbs/core/api_contract.py` as the "Arabic" argument, which
   accepted it (it was non-empty) and stored English prose under `summary.ar` —
   confirmed live in the database, e.g. `analysis_conclusions.summary_json` for
   `possible_anemia_pattern` had `ar: "One or more red blood cell measurements
   are low, which may suggest an anemia pattern."` (English text, not Arabic).
4. `Evidence.label` and `normalized_results[].display_name` were always
   English-only — sourced from `tests.json`'s `name` field, ignoring the
   co-located, schema-required `name_ar` field that already existed for every
   analyte.
5. Gemini (Phase 4E and Phase 4C) received this partially-English "authoritative"
   context, and — live-verified during the audit — sometimes translated it
   unprompted and sometimes echoed it verbatim, inconsistently, even across two
   calls with identical source data.
6. Neither response validator checked whether Arabic-designated prose actually
   contained Arabic; only the `language` metadata field was checked.

Laravel's KBS ingestion (`KbsClient`, the old `KbsResponseValidator`) and the
frontend's `localizeAnalysisText` fallback were **not** the root cause — both
correctly preserved/exposed whatever bilingual shape KBS sent.

## The invariant this repair establishes

> When `language=ar`, every human-readable prose field must contain genuine
> Arabic. Intentional Latin-script tokens — `HGB`, `MCV`, `HbA1c`, `ALT`, `AST`,
> `ALP`, `GGT`, `CBC`, rule/condition codes (`R001`, `CBCX_R002`, ...), units
> (`g/dL`, `U/L`, `fL`, `pg`, `%`), and numeric values — are not violations of
> this invariant and must never be stripped or rejected.

## KBS repair (`kbs/`)

- `kbs/knowledge_base/conditions.json` — all 20 base conditions now have
  `name_ar`, translated to preserve the exact hedging strength of the English
  (e.g. "may suggest" → `قد يشير إلى`, never strengthened to `يؤكد`/`يثبت`).
- `kbs/knowledge_base/rules.json` — all 19 previously-missing active rules
  (R001–R016, R031–R033) now have `explanation_ar`. **Target achieved: 0 active
  production rules missing a required Arabic explanation.** Verified by a new
  KBS validator gate (below) that runs on every `analyze_patient()` call.
- `kbs/core/report_builder.py` — `why_ar` no longer falls back to English; a
  rule without `explanation_ar` contributes nothing to `why_ar` rather than
  substituting English. Combined with the conditions.json fix, `summary.ar` for
  every currently-active CBC/glucose conclusion is now genuine Arabic; a
  not-yet-translated rule (there are currently none) would produce `ar: null`,
  never English-under-`ar`.
- `kbs/core/api_contract.py::_localized()` — unchanged in behavior (it was
  already correct: `en` required, `ar` included only when truthy). Documented
  with an explicit contract note. The bug was entirely in what callers passed
  as the "arabic" argument, not in this function.
- `kbs/core/observations.py` / `kbs/core/api_contract.py` — every analyte
  observation now carries `display_name_ar` (sourced from `tests.json`'s
  already-existing `name_ar`), propagated into `normalized_results[].display_name_ar`,
  `Evidence.label_ar`, and `category_requirements_metadata()`'s
  `AnalyteRequirement.display_name_ar`. `display_name`/`label` (English) are
  **unchanged** — this is a backward-compatible companion field, matching the
  `name`/`name_ar` convention already used elsewhere in the KBS, not a breaking
  schema change.
- `kbs/api/schemas.py` — `NormalizedResult.display_name_ar`,
  `Evidence.label_ar`, `AnalyteRequirement.display_name_ar` added as optional
  (`str | None = None`) fields. `output_schema_version` was **not** bumped —
  this is a purely additive change to a versioned contract, not a breaking one.
- `kbs/core/liver_engine.py` — the 7 rules whose English explanation embeds a
  dynamically computed value (`r_gt_5`, `r_lt_2`, `r_2_to_5`,
  `direct_predominance`, `indirect_predominance`, `abnormal_total_protein`,
  `ast_alt_ratio_ge_2`) now embed the identical computed value in the Arabic
  explanation too (appended parenthetically to the existing, unmodified,
  pre-approved Arabic sentence — no new medical interpretation). Note: for
  every current `liver_conditions.json` entry the *English* side of
  `conclusions[].summary` is sourced from the condition's static `description`
  (not the dynamic rule explanation), so this parity fix does not currently
  change the live API's visible summary text — it closes a latent asymmetry in
  the underlying rule-result data structure that would otherwise resurface the
  moment a condition's static description is removed or a new liver condition
  without one is added.
- `kbs/core/validator.py::validate_active_localization()` (new) — runs on every
  `analyze_patient()` call (via `validate_knowledge_base()`): every *active*
  rule must have non-empty `explanation_ar`; every condition reachable by an
  active rule must have non-empty `name_ar`. This does not check language
  content (no "reject Latin characters" logic) — only field presence. A future
  regression (a new active rule shipped without its Arabic explanation) fails
  loudly at analysis time instead of silently reaching users.
- `kbs/knowledge_base/metadata.json` — version bumped to `2026.08.16.1`
  (content-only change; no rule logic, threshold, or condition/rule id changed).

**Dormant/deferred, deliberately untouched:**
- `kbs/knowledge_base/red_flags.json` / `kbs/core/experta_engine.py` — confirmed
  not reachable by any live path. All 13 red flags are `active: false`,
  `review_status: unverified_disabled`, and have empty `source_ids` — `check_red_flags()`
  gates on all three, so zero warnings can currently be produced. `experta_engine.py`
  is never imported by `analyzer.py` (which uses `rule_engine.run_rule_engine`
  instead) or by the live FastAPI app. Left untouched; the wiring code exists
  and would produce mixed-language output if a flag were ever activated without
  first adding `message_ar`, which is noted here for whoever does that.
- `kbs/core/questions.py` — confirmed not reachable by any live path. The real
  General Question Bank generator (`backend/app/Services/Quiz/GeneralQuestions/`)
  is a separate, pure-PHP implementation that reads `kbs/knowledge_base/*.json`
  directly from disk and maintains its own already-complete, independently
  reviewed bilingual condition-name catalog (`ConditionNameCatalog.php`, 51
  entries) — it does not use `conditions.json`'s `name_ar` or `rules.json`'s
  `explanation_ar` at all. The English-name-interpolated-into-Arabic-question
  bug the audit found in `kbs/core/questions.py` is real but confined to dead
  code; the live Question Bank was not affected.

## Laravel repair (`backend/`)

- `app/Services/Ai/ResultExplanationContextBuilder.php` /
  `ComparisonContextBuilder.php` — evidence `label`/analyte `display_name` now
  prefer the KBS-supplied `_ar` sibling when `language=ar` and it exists
  (`localizedField()` helper), instead of always sending the English value to
  Gemini.
- `app/Services/Comparison/BuildReportComparison.php` /
  `CompareAnalyteSeries.php` — propagate `display_name_ar` through the
  deterministic comparison structure (purely additive; no trend/value
  calculation changed — verified by the unchanged Phase 4C test suite).
- `app/Services/Ai/ResultExplanationPromptBuilder.php` /
  `GeminiPromptBuilder.php` — added an explicit instruction: when
  `language=ar`, if a supplied English label/title happens to appear in the
  context, Gemini must render its *meaning* in Arabic rather than copying it
  verbatim, while still preserving the existing Latin-script whitelist
  (abbreviations, units, codes, numbers) unchanged.
- `app/Services/Ai/LanguagePurityChecker.php` (new) — a conservative,
  token-aware check: strips numeric values, known lab units, `HbA1c`, and any
  ALL-CAPS or alphanumeric-code Latin token (the exact categories the prompt
  whitelists), then rejects the remaining text only if (a) it contains a run of
  2+ ordinary lowercase-starting Latin words (untranslated English prose), or
  (b) after stripping there is meaningful remaining text but zero Arabic-script
  characters anywhere in the original string (an entirely English single-word
  or short field). A lone Title-Case English label next to its value (e.g.
  `"Hemoglobin 9.5 g/dL"`) is deliberately **not** rejected — matching the
  audit's own worked distinction between acceptable mixed content and
  unintended English prose. Documented false-positive/false-negative
  limitations are in the class docblock. Not a general-purpose language
  detector; scoped specifically to Gemini's structured output.
- `app/Services/Ai/ResultExplanationResponseValidator.php` /
  `ComparisonResponseValidator.php` — every prose field is now checked with
  `LanguagePurityChecker` when `expectedLanguage === 'ar'`. A response with
  `"language": "ar"` and English prose (the exact historical bug shape) is now
  rejected and falls back to the deterministic formatter. English responses are
  not language-purity-checked (no confirmed reverse failure mode).
- `app/Services/Ai/ResultExplanationFallbackFormatter.php` /
  `ComparisonFallbackFormatter.php` — the deterministic (non-AI) fallback now
  uses the Arabic evidence/analyte label when available instead of always
  injecting the English one into an otherwise-Arabic sentence — live-reproduced
  before the fix as e.g. `"استُخدمت القيم التالية لدعم هذه الملاحظة: Hematocrit
  29 %, Hemoglobin 8.9 g/dL, Red Blood Cells 4.1 million cells/mcL."`
- `app/Services/Kbs/KbsResponseValidator.php` — hardened structurally, not
  linguistically: a `LocalizedText`-shaped field must have a non-empty `en` and,
  if `ar` is present at all, a non-empty `ar` (never an empty string or wrong
  type). Separately, a non-blocking `Log::warning()` fires if `ar` contains zero
  Arabic-script characters (the structural shape of the historical bug) — this
  never fails a real analysis; it exists purely as a regression tripwire for a
  future KBS release. Laravel remains explicitly **not** responsible for
  translating or validating medical content.
- `config/ai.php` — `result_explanation.prompt_version` bumped `v1` → `v2` so
  no pre-repair cached explanation is ever served after this deploy; Phase 4C
  has no cache table at all (stateless by design), so nothing to bump there.

## Historical data repair

`ai_explanations` was empty both before and after this repair (nothing to
invalidate). Historical `analyses`/`analysis_conclusions` rows persisted before
the KBS fix could genuinely contain English prose under `ar` — this is data at
rest, not something any prompt/validator change touches.

### `php artisan kbs:repair-localized-analysis-content`

A **localization-only** backfill, never a medical re-analysis:

```bash
php artisan kbs:repair-localized-analysis-content              # dry run (default)
php artisan kbs:repair-localized-analysis-content --dry-run    # dry run (explicit)
php artisan kbs:repair-localized-analysis-content --apply      # writes
```

- Scans every `SUCCEEDED` analysis's `analysis_conclusions` (title/summary/
  evidence labels), `rule_traces` (evidence labels), and
  `analyses.normalized_results_json` (display names).
- Maps **only** by stable identifier — `conclusion_code` → `conditions.json`'s
  `name_ar`; `rule_codes_json` (reconstructing the same `why_ar` join
  `api_contract.py` performs) → `rules.json`'s `explanation_ar`; `analyte_id` →
  `tests.json`'s `name_ar`. Never fuzzy or free-text matching. A row that
  cannot be safely mapped is skipped and counted, never guessed.
- Detects "needs repair" as: `ar` missing, OR `ar` present but containing zero
  Arabic-script characters (the confirmed bug shape — this is *not* the same
  check as `ar === en`, since the real bug could assemble a *different* English
  sentence under `ar` while `en` held the condition's own English description).
- Never touches `rule_codes`, `conclusion_codes`, analyte values/units/statuses,
  analysis `status`, `verified_result_set_id`, or the analysis's original
  `started_at`/`completed_at`/`created_at` — verified by a dedicated regression
  test and, on the real dev database, by direct before/after inspection.
- Dry-run by default; `--apply` required to write. Each chunk (default 200
  `analysis_conclusions` rows) commits in its own transaction — a failure rolls
  back only that chunk. Idempotent — a repaired row no longer matches the
  "needs repair" condition, so re-running with `--apply` reports 0 further
  changes.

Real dev-database run (20 analyses, 71 conclusions): 44 titles repaired, 43
summaries repaired, 161 conclusion evidence labels + 162 rule-trace evidence
labels + 234 normalized-result display names backfilled, 0 unrepairable, 0
errors. A second `--apply` run reported 0 repairs needed (idempotent). Verified
directly: `possible_anemia_pattern`'s title went from `ar: null` to `نمط فقر
الدم المحتمل`; its summary went from the English-under-`ar` bug shape to `انخفاض
واحد أو أكثر من قياسات الكريات الحمراء قد يشير إلى نمط فقر الدم.`; a LIVER_FUNCTION
conclusion that was already correctly bilingual before the run was confirmed
byte-for-byte unchanged after it.

## Frontend repair (`frontend/`)

- `src/types/analysis.types.ts` — `AnalysisEvidence.labelAr`,
  `AnalysisNormalizedResult.displayNameAr` (both `string | null`) added.
  `src/types/comparison.types.ts` — `ComparisonAnalyte.displayNameAr` added.
- `src/features/analysis/analysisContract.ts` /
  `src/features/comparison/comparisonContract.ts` — map `label_ar`/
  `display_name_ar` from the API response.
- `src/components/analysis/AnalysisResultSections.tsx` — new `localizeField()`
  helper (same intent as the existing `localizeAnalysisText()`, for flat
  `en`/`ar` sibling fields instead of a `LocalizedText` object). `EvidenceRail`
  now takes a `language` prop and renders `localizeField(item.label,
  item.labelAr, language)` instead of the raw English label; both call sites
  (`AnalysisFindings`, `AnalysisRuleTraces`) thread `language` through.
- `src/components/comparison/AnalyteTrendCard.tsx` — renders
  `analyte.displayNameAr` when in Arabic mode and available, else the existing
  English `displayName`.
- **`localizeAnalysisText()` itself is unchanged** — it remains a deliberate
  defensive fallback (per the audit's own conclusion that it was never the root
  cause). After the KBS fix, hitting its English-fallback branch for
  `title`/`summary`/`message`/`disclaimer` on a current analysis should be
  exceptional rather than routine; it is not expected to be exercised by any
  currently-active rule/condition.

## Tests

- KBS: `kbs/tests/test_localization.py` (23 tests) — CBC/DIABETES conclusion
  titles and summaries are genuine Arabic (checked via actual Arabic-script
  presence, not just non-empty); LIVER titles/summaries remain genuine Arabic;
  the `why_ar` fallback fix (direct `report_builder.build_report()` unit
  tests proving a rule without `explanation_ar` contributes nothing, never
  English); liver dynamic-value parity for all 7 affected rules plus a
  regression proving static liver rules are untouched; `display_name_ar`/
  `label_ar` presence on live `/v1/analyze` and `/v1/metadata` responses;
  machine tokens (rule codes, units) remain untranslated; zero active
  rules/conditions/analytes missing required Arabic text (completeness
  targets). Full suite: 186/186 passing (162 pre-existing + 24 new, including
  4 for the new `validate_active_localization()` gate).
- Laravel: `tests/Unit/Ai/LanguagePurityCheckerTest.php` (8 tests, using real
  strings captured live during the audit); new negative tests in
  `tests/Feature/Phase4E/ResultExplanationAiTest.php` and
  `tests/Feature/Phase4C/ComparisonAiTest.php` proving a `language: "ar"`
  response with English prose is now rejected (previously accepted — the exact
  historical bug, now a locked-in regression test) and that whitelisted
  abbreviations/units/codes still pass; `tests/Feature/Kbs/RepairLocalizedAnalysisContentTest.php`
  (5 tests) for the historical repair command, including a real (non-mocked)
  KBS-catalog lookup and an explicit medical-field-immutability assertion. Full
  suite: 342/342 passing (326 pre-existing baseline + 16 new), `pint --dirty`
  clean.
- Frontend: 4 new tests across `tests/analysis-contract.test.ts` (bilingual
  evidence/display-name mapping, null-default safety, source-string regression
  checks for the new render call sites) and `tests/comparison.test.ts`
  (`displayNameAr` mapping). Full suite: 146/146 passing (142 pre-existing +
  4 new), `tsc --noEmit` and `expo lint` both clean.

## Medical-inference regression proof

Before/after fingerprint comparison (fired rules, conclusion codes, normalized
numeric values, reference statuses, missing-information codes, rule-trace
codes, analysis status) for CBC/DIABETES/LIVER_FUNCTION, captured via `git
stash` isolating the pre-fix KBS code: **zero diff**. Only localized
human-readable content changed. Confirmed again live on the real dev database
via the historical repair command's before/after inspection and a full
end-to-end run through the freshly-restarted live KBS HTTP server + Laravel
queue worker (real network calls, not test fakes), including a live Gemini
call for both Regular and Student roles in Arabic — previously the Student
role specifically left titles like "Possible microcytic anemia pattern"
completely untranslated even when Regular correctly translated the same
source data; after the fix, both roles produced 100% genuine Arabic titles for
every finding, with zero English leaks, and the English-language path showed
no regression.

## Known remaining limitations

- `LanguagePurityChecker` is a heuristic, not a real language-detection model —
  its documented false-positive/false-negative cases (see its docblock) are
  accepted trade-offs given the confirmed real-world failure mode is far more
  common and severe than the edge cases it might miss or over-flag.
- The liver dynamic-value parity fix does not currently change any visible API
  output (see above) — it is forward-looking hygiene for the underlying rule
  engine, not a currently-observable behavior change.
- `red_flags.json`/`experta_engine.py` localization debt remains dormant and
  untouched, as scoped.
- No visual RTL/UI verification was performed as part of this repair — all
  verification is structural (code review) and live-data (real API/Gemini
  responses over HTTP). See the final report for the explicit visual-QA
  limitation statement.
