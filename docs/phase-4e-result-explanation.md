# Phase 4E — Role-Aware AI Result Explanation

This document is the operational contract for Phase 4E: an optional, role-aware AI
explanation layer over an already-completed deterministic KBS `Analysis`, persisted in
a versioned cache separate from the deterministic truth tables. It does not include
Phase 4F (whatever comes next) or any diagnosis/treatment functionality — Gemini only
explains what KBS already concluded; it never computes a new medical fact.

## Two-layer architecture (unchanged principle from Phase 4C)

```text
Stored Verified Results -> KBS -> deterministic Analysis/Conclusions/RuleTraces
                                          |
                                          v
                          Role-Aware Context Builder (Laravel)
                                          |
                                          v
                                       Gemini
                                          |
                                          v
                         Strict JSON Response Validator (Laravel)
                                          |
                                          v
                     Versioned ai_explanations Cache (Laravel/MySQL)
                                          |
                                          v
                                     Result UI
```

The medical reasoning flow is always **Verified Results → KBS → deterministic
conclusions → Gemini explanation**, never the reverse. Gemini cannot alter, remove, or
add to what KBS already concluded — it can only explain those already-fixed facts, at
a depth appropriate to the requesting user's role. The AI explanation is **visually**
first on the result screen (task requirement); it is never **logically** first — the
KBS conclusions it explains already existed before any Gemini call was made.

## Audit: reused Phase 4C AI infrastructure

Before writing any new code, the existing Phase 4C AI stack
(`app/Services/Ai/*`, `app/Contracts/AiContextualizer.php`, `config/ai.php`,
`AppServiceProvider`) was read in full. Reused **directly, unmodified**:

- **`GeminiClient`** — already fully generic (takes `systemInstruction`, `userContent`,
  `responseSchema`; returns decoded JSON; knows nothing about "comparison"). Phase 4E
  uses this exact same class, with zero changes, for every Gemini HTTP call.
- **`config('ai.gemini.*')`** — base URL, model, API key, timeouts, retry attempts are
  entirely shared; Phase 4E never re-reads or duplicates this connectivity config.
- **`AiContextStatus`** enum (`AVAILABLE`/`FALLBACK`) — reused as-is for the outward
  response contract, so both AI features share one predictable status shape.

**Not reused, deliberately rebuilt in parallel** (mirroring the same architecture, not
sharing the same classes): `GeminiPromptBuilder`, `ComparisonContextBuilder`,
`ComparisonResponseValidator`, `ComparisonFallbackFormatter`, `GeminiContextualizer`,
and the `AiContextualizer` contract are all comparison-shaped by design (their schema
literally has `analyte_insights`/`kbs_context` fields) and were never intended to be
generic. Phase 4E adds an exactly analogous, independent family
(`ResultExplanationPromptBuilder`, `ResultExplanationContextBuilder`,
`ResultExplanationResponseValidator`, `ResultExplanationFallbackFormatter`,
`GeminiResultExplainer`, `ResultExplainer` contract) rather than forcing a different
task shape through the Comparison-specific classes. This matches the task's own
instruction: reuse generic infrastructure, but don't force-fit two different domains
into one set of task-specific classes just to avoid a second file.

**One genuine, non-breaking refactor was made**: the small English+Arabic forbidden-
keyword regex list that used to live only inside `ComparisonResponseValidator` was
extracted into `App\Services\Ai\MedicalSafetyPatterns` (a static helper), and
`ComparisonResponseValidator` now calls it instead of keeping its own private copy.
`ResultExplanationResponseValidator` uses the exact same helper. This was judged
"genuinely cleaner and does not break Phase 4C" (confirmed: all 43 Phase 4C tests still
pass unchanged) rather than duplicating the same eight regex patterns in two files that
could silently drift apart.

## Config additions (`config/ai.php`)

```php
'gemini' => [ /* unchanged - shared by both features */ ],
'result_explanation' => [
    'enabled' => (bool) env('AI_RESULT_EXPLANATION_ENABLED', true),
    'prompt_version' => env('RESULT_EXPLANATION_PROMPT_VERSION', 'v1'),
],
```

`GeminiClient::isConfigured()` (`ai.gemini.enabled` + a real API key) remains the
**shared master gate** for both features — despite its legacy `AI_COMPARISON_CONTEXT_
ENABLED` env var name (kept for backward compatibility with already-deployed `.env`
files rather than a breaking rename), it is the single on/off switch Gemini
connectivity depends on everywhere. `AI_RESULT_EXPLANATION_ENABLED` is a **narrower**,
Phase-4E-specific gate on top of that shared one — disabling it degrades only result
explanation to its deterministic fallback while leaving Phase 4C comparison
contextualization fully unaffected (verified by a dedicated test). `RESULT_EXPLANATION
_PROMPT_VERSION` is the prompt/schema version stamped into every cached row (see
"Prompt/schema versioning" below).

## The `ai_explanations` cache table

```php
Schema::create('ai_explanations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
    $table->string('task_type', 32);      // 'RESULT_EXPLANATION' today
    $table->string('language', 5);        // 'ar' | 'en'
    $table->string('role', 16);           // 'student' | 'regular'
    $table->string('schema_version', 8);  // '1'
    $table->string('prompt_version', 16); // 'v1'
    $table->string('model', 64)->nullable();
    $table->json('content_json');
    $table->string('status', 16)->default('AVAILABLE');
    $table->timestamps();
    $table->unique(['analysis_id', 'task_type', 'language', 'role', 'prompt_version', 'schema_version'], 'ai_explanations_cache_identity');
});
```

Entirely separate from `analyses`/`analysis_conclusions`/`rule_traces` — the migration
never touches those tables, and no AI-generated content is ever written into the
deterministic truth columns. `task_type` (`App\Enums\AiTaskType`, currently only
`ResultExplanation = 'RESULT_EXPLANATION'`) keeps this table safely shared with any
future AI presentation feature without a schema change or data mixing — Phase 4C
comparison contextualization remains fully stateless and never writes to this table at
all.

### Cache identity

The unique index `(analysis_id, task_type, language, role, prompt_version,
schema_version)` **is** the cache identity, matching exactly what the task specified.
A cache lookup or write always provides all six fields; there is no "latest for this
analysis" query anywhere - only exact-identity lookups.

### Cache concurrency

`App\Services\Ai\AiExplanationCache::store()` always attempts a plain `create()` first.
If two requests race to generate the same identity, the loser's `INSERT` fails on the
unique index (`SQLSTATE 23000` / MySQL error `1062`), which is caught explicitly;
the loser then reads back whatever the winner just wrote and returns that instead of
erroring or creating a duplicate row. Verified by a dedicated test that calls
`store()` twice with the identical identity and asserts exactly one row exists and both
calls return the same row.

### Fallback is never persisted

`GeminiResultExplainer::explain()` only ever calls `AiExplanationCache::store()`
after a Gemini response has passed `ResultExplanationResponseValidator` — a fallback
result is constructed fresh and returned directly, **never** written to the cache
table. This means a cache miss always means "not yet successfully generated", never
"Gemini failed last time" — the very next request for that same identity is always
free to try Gemini again rather than being stuck showing a stale fallback forever.
Verified directly: after every fallback-producing scenario in the test suite,
`AiExplanation::count()` stays `0`.

## `POST /api/v1/analyses/{analysis}/explanation`

POST, not GET, because a cache miss causes a real database write (a new
`ai_explanations` row) — matching the same "a lazy write means POST" reasoning Phase
4C's comparison endpoint already used for its own 200-vs-201 decision.

```json
// Request
{ "language": "ar" }
```

`role` is **never** accepted from the client — `RequestResultExplanationRequest`'s
validation rules deliberately omit it, and the controller always derives it from
`$request->user()->role` (`UserRole::Student` → `'student'`, otherwise `'regular'`).
A test explicitly proves sending `role: 'student'` in the request body has no effect
for a Regular-role account.

```json
// Response
{
  "success": true,
  "data": {
    "analysis_id": 46,
    "status": "AVAILABLE",
    "language": "ar",
    "role": "regular",
    "content": {
      "schema_version": "1", "language": "ar", "category": "CBC", "role": "regular",
      "overview": "...",
      "important_findings": [
        { "conclusion_code": "microcytic_anemia_pattern", "title": "...", "explanation": "...", "evidence_explanation": "..." }
      ],
      "missing_information_explanation": "...",
      "educational_takeaway": "...",
      "limitations": "..."
    }
  }
}
```

### Authorization (reused, not reinvented)

1. `Gate::authorize('view', $analysis)` — the exact same `AnalysisPolicy::view()`
   ownership check (`analysis.user_id === auth user id`) `ShowAnalysisController`
   already uses. A non-owner gets `403 FORBIDDEN`.
2. `Analysis::isPendingQuizCompletion()` — the exact same Result-Locking check
   `ShowAnalysisController` already enforces. A locked quiz-first analysis returns
   `403 QUIZ_RESULT_LOCKED` for its explanation too, so a Student can never read an AI
   explanation of a result they are not yet allowed to see the deterministic version
   of either.
3. `$analysis->status !== Succeeded` → `409 EXPLANATION_NOT_AVAILABLE` — an
   explanation is only meaningful once KBS has actually produced a result.

### Error codes

| Code | HTTP | Meaning |
| --- | --- | --- |
| `VALIDATION_ERROR` | 422 | missing/invalid `language` |
| `FORBIDDEN` | 403 | caller does not own the analysis |
| `QUIZ_RESULT_LOCKED` | 403 | same Phase 3B.3 quiz-first result lock |
| `EXPLANATION_NOT_AVAILABLE` | 409 | analysis has not succeeded yet |

## Exact data sent to Gemini (`ResultExplanationContextBuilder`)

```json
{
  "task": "result_contextualization",
  "language": "ar",
  "user_role": "regular",
  "category": "CBC",
  "analysis": {
    "summary": "...",
    "conclusions": [{ "code": "...", "level": "...", "title": "...", "summary": "...", "evidence": [...], "rule_codes": [...] }],
    "fired_rule_codes": ["R001", "R002"],
    "missing_information": [{ "code": "...", "analyte_id": "...", "message": "..." }]
  },
  "verified_or_normalized_analytes": [{ "analyte_id": "...", "display_name": "...", "value": ..., "unit": "...", "status": "...", "reference_range": {...} }],
  "allowed_medical_context": []
}
```

Only the KBS-structured `normalized_results_json` is sent for analyte values —
**never** the free-text `VerifiedResult.reference_range` string (same rule Phase 4C's
`ComparisonContextBuilder` already follows: free text is not safely machine-parseable).
`allowed_medical_context` stays permanently empty for this MVP — no approved,
medically-reviewed symptom catalog exists in this project yet (identical decision to
Phase 4C's own documented choice); the prompt instructs Gemini to discuss no symptoms
at all whenever it is empty.

### Data explicitly excluded

Never sent, verified by dedicated privacy-contract tests: patient/account name, email,
Sanctum token/API token, report filename, PDF/image content, raw OCR text, internal
storage paths, idempotency keys, `report_id`/`analysis_id`/`user_id` database
identifiers, or any quiz/quiz-history data. The context builder only ever reads from
`Analysis`'s own already-computed JSON columns plus a plain `role` string and
`language` string — it never receives a `User` model at all.

## Prompt strategy (`ResultExplanationPromptBuilder`)

A dedicated system instruction — **not** a reuse of `GeminiPromptBuilder`'s comparison
prompt — built in code (not copied from any external source verbatim), establishing:
explanation-only role, immutable-fact treatment of every supplied conclusion/evidence/
status/missing-information item, a role-conditioned depth paragraph (Student: "more
scientific, academic... explain relationships between analytes... why each conclusion
is supported by its evidence and fired rule code(s)"; Regular: "clear, non-technical
where possible... avoid overwhelming the reader with rule codes"), the same
prohibitions Phase 4C's prompt uses (no recalculation, no new diagnosis, no treatment/
dosage, no confirmed-recovery claims, no hiding missing information, `allowed_medical_
context`-gated probabilistic symptom mentions only), strict-JSON-only output, and a
`language`-driven human-readable-content rule with stable English JSON keys.

For Arabic, the same professional-MSA-with-Latin-abbreviations instruction Phase 4C
already uses is reused verbatim (`HGB`, `MCV`, `HbA1c`, `ALT`, `AST` stay Latin) —
confirmed live to actually happen in real model output (see below).

## JSON schema

```json
{
  "schema_version": "1", "language": "ar", "category": "CBC", "role": "regular",
  "overview": "string",
  "important_findings": [{ "conclusion_code": "string", "title": "string", "explanation": "string", "evidence_explanation": "string" }],
  "missing_information_explanation": "string",
  "educational_takeaway": "string",
  "limitations": "string"
}
```

`schema_version`, `language`, `category`, and `role` are all pinned via a single-value
`enum` in Gemini's `responseSchema` (not left as free-form strings) — the same
enum-pinning fix Phase 4C's live testing discovered was necessary (a free-text field
lets the model drift, e.g. echoing `"1.0"` instead of the literal `"1"`).

`important_findings` deliberately has **no** `analyte_id` or `rule_code` field — the
only identifier surface Gemini can reference in its response is `conclusion_code`,
which is allow-listed against the analysis's own conclusions. This is a narrower
response schema than Comparison's (which does expose `analyte_id`/`rule_code`
fields), and was a deliberate design choice: fewer identifier fields in the output
means fewer places an invented identifier could ever appear at all, not just fewer
places it gets caught.

## Response validator (`ResultExplanationResponseValidator`)

Mirrors `ComparisonResponseValidator`'s exact discipline: strict top-level key
allow-list (no extra/missing fields), `schema_version`/`language`/`category`/`role`
exact match, bounded string lengths (overview/explanations ≤1200–1500 chars),
`important_findings` bounded to ≤20 items with no duplicate `conclusion_code`, and —
the primary defense — every `conclusion_code` **must already exist** in this specific
analysis's own persisted conclusions (`ResultExplanationContextBuilder::
allowedConclusionCodes()`). Every text field is additionally checked against
`MedicalSafetyPatterns` (shared with Phase 4C). Any single violation returns `null`,
and the caller always falls back — there is no partial-trust path. `analyte_id`/
`rule_code` allow-listing exists in the context builder (`allowedAnalyteIds()`/
`allowedRuleCodes()`) for potential future schema use, but since the current response
schema has no field for either, there is currently nothing for the validator to check
there — the schema's own shape is the primary safeguard against that class of
injection.

## Medical safety guards

Same three-layer defense as Phase 4C: (1) minimal input (Gemini only ever sees this
one analysis's own already-computed facts), (2) strict structured output + allow-
listed `conclusion_code`, (3) a narrow supplementary keyword blocklist
(`MedicalSafetyPatterns`) for treatment/dosage instructions and confirmed-cure/
diagnosis claims — explicitly **not** presented as comprehensive medical-content
validation, since regex cannot verify medical correctness.

## Fallback (`ResultExplanationFallbackFormatter`)

Produces the identical schema without calling Gemini, built purely from the
Analysis's own already-persisted, already-localized `summary_json`/conclusions'
`title_json`/`summary_json`/`evidence_json`/`missing_information_json` — no new
medical text is ever invented. Role-aware at the sentence-template level (Student:
"Supported by the following evidence: ..."; Regular: "The following values support
this finding: ..."), fully bilingual, and honors the exact requested language (an
Arabic request never silently shows English).

## AI-disabled / any-failure behavior

`GeminiResultExplainer::explain()` never throws. Every one of the following degrades
to the fallback formatter, with `status: FALLBACK`, and the request still returns
`200 OK`: `AI_RESULT_EXPLANATION_ENABLED=false`, `AI_COMPARISON_CONTEXT_ENABLED=false`
(the shared gate), missing `GEMINI_API_KEY`, connection timeout, HTTP 429/5xx, invalid
JSON text, and any schema/safety validation failure.

## Cache hit/miss and language/role variants

- **Cache hit**: `AiExplanationCache::find()` returns an existing row for the exact
  `(analysis_id, task_type, language, role, prompt_version, schema_version)` tuple →
  returned immediately, Gemini is never called (`source: CACHED` internally).
- **Cache miss + Gemini succeeds**: validated content is persisted, then returned
  (`source: GENERATED`).
- **Cache miss + Gemini fails**: fallback is returned, nothing persisted
  (`source: FALLBACK`).
- A different `language` or a different `role` for the same analysis is a **different**
  cache identity entirely — both may legitimately coexist (e.g. `student+ar` and
  `student+en` for the same `Analysis`), generated lazily only when actually
  requested, never pre-generated in bulk.

## Prompt/schema versioning

`prompt_version` (`RESULT_EXPLANATION_PROMPT_VERSION`, currently `v1`) is part of the
cache identity. If the prompt or schema changes meaningfully in the future, bumping
this value causes every new request to generate (and cache) fresh content under the
new identity, while old `v1` rows remain untouched in the table — nothing is silently
overwritten or mutated. Verified directly by a test that changes the config value
mid-test and asserts two independent cache rows exist afterward.

## Guest behavior (audited, not invented)

Confirmed by repository audit (`grep -ri guest` across the entire `app/` directory:
zero matches) that the backend has **no concept of a guest session at all** — every
`auth:sanctum`-protected route, including this one, requires a real authenticated
`User` with role `regular` or `student`. `frontend/README.md`'s own "Supported Roles"
section already documents that a guest session cannot currently upload, review, or see
results for any report at all (`app/analysis/_layout.tsx` shows a "sign in required"
wall before any real report/analysis is ever created). Since Guest cannot reach a real
succeeded `Analysis` in the current product, **no Guest-specific AI explanation
architecture was built** — there is nothing to explain for a session that can never
own an `Analysis` row. This is a limitation of the already-existing Guest report flow,
not something newly introduced or newly discovered as a gap by this phase.

## Frontend

### Store

`resultExplanationStore.ts` — a transient fetch-by-`(analysisId, current app
language)` cache (generation-guarded, mirroring `reportDetailsStore`/
`quizDetailStore`), **not** the source of truth (the backend's own versioned cache is).
Clears and refetches synchronously whenever either the requested `analysisId` or the
live app language changes, so a stale-language explanation is never shown after a
language switch. Wired into `authStore.ts`'s `clearSession()`/`continueAsGuest()`
resets.

### Component

`ResultContextualExplanationCard` — one component, reused by **both**
`app/analysis/result.tsx` (live Final Result) and
`app/(student)/report-details/[id].tsx` (Phase 4B historical details), never
duplicated. Handles loading (`"Preparing explanation…"`), available, and fallback
states with the exact same calm visual treatment (no alarming "error" styling for a
fallback, matching Phase 4C's `AiContextCard` philosophy), and a subtle "Retry
explanation" link that only appears when the current result is a fallback. Title reads
`t('resultExplanation.title')` ("Contextual Explanation") for a Regular user or
`t('resultExplanation.titleStudent')` ("Scientific Contextual Explanation") for a
Student — never "AI Diagnosis" in either language.

### Result-screen layout

On both screens, the card renders immediately after the hero/header and **before**
`AnalysisSummaryStats`/`AnalysisFindings`/`AnalysisVerifiedValues`/`AnalysisRuleTraces`
— visually first, exactly as required — while every deterministic KBS section remains
unconditionally in the render tree regardless of the AI card's own loading/available/
fallback state (verified by a source-level regression test). `AnalysisRuleTraces` was
already collapsed-by-default with its own `[Show details ▼]` toggle before this phase
(Phase 4B), so the "Technical Details less prominent for Regular, more directly
accessible for Student" requirement is satisfied by the AI explanation's own role-
appropriate depth rather than by building two separate layout trees — the same shared
`AnalysisResultSections` components render for both roles, avoiding the duplicated-
rendering-tree the task explicitly warned against.

## Testing

- `tests/Feature/Phase4E/ResultExplanationApiTest.php` (16 tests) — auth, cross-user
  rejection, quiz-result-lock reuse, not-yet-succeeded rejection, language validation,
  role-impersonation rejection, Regular/Student profile selection, full response
  contract + Arabic, cache-hit-skips-Gemini, language-variant/role-variant/prompt-
  version-variant independent caching, cache-write concurrency safety, no Analysis/
  conclusion/rule-trace mutation, no OCR/KBS rerun.
- `tests/Feature/Phase4E/ResultExplanationAiTest.php` (19 tests) — valid JSON accepted
  (English + Arabic), invalid JSON/wrong category/wrong role/unsupported conclusion
  code/treatment-language/cure-claim/missing-field/extra-field/oversized-list all fall
  back; timeout/429/5xx/missing-key/both-disabled-gates all fall back; the fallback
  honors the requested language; the result stays fully successful regardless. The
  real Gemini API is never called in this suite.
- `tests/Unit/Phase4E/ResultExplanationContextBuilderTest.php` (7 tests) — the
  privacy contract: no name/email/token/filename/storage-path/idempotency-key leakage,
  correct language/role/category, empty `allowed_medical_context`, allow-list helper
  correctness, and confirmation that only KBS-structured (never free-text) reference
  ranges are sent.

## Known limitations

- No Guest AI explanation architecture exists, matching Guest's own pre-existing
  inability to reach a real Analysis at all (see above).
- The response schema has no `analyte_id`/`rule_code` field, so those allow-lists
  (already built in the context builder) are not currently exercised by the
  validator — a deliberate narrower-surface design choice, documented above.
- The Approved Medical Context Catalog (see the 2026-08-17 update below) covers 17 CBC,
  4 diabetes, and 5 liver-function conclusion codes. Any KBS conclusion code without a
  matching catalog group produces empty causes/symptoms/next-steps/red-flags/student-
  context arrays for that finding rather than a Gemini-invented explanation — expanding
  coverage requires adding a new reviewed, source-grounded group to the catalog, not a
  code change.
- `MedicalSafetyPatterns`'s keyword net remains a narrow supplementary safety check,
  not comprehensive medical-content validation — two false-positive patterns were found
  and fixed during this redesign's live verification (see below), and a future prompt
  change could in principle surface a similar false positive again; structural
  allow-listing of `context_code`s remains the primary defense, not this regex list.

## 2026-08-16 update: Arabic/English localization integrity repair

`ResultExplanationContextBuilder` now prefers KBS's `label_ar`/`display_name_ar`
sibling fields for evidence/analyte names when `language=ar`, instead of always
sending the English value. `ResultExplanationPromptBuilder`'s system instruction
now explicitly tells Gemini to render an English-sourced label's *meaning* in
Arabic rather than copying it verbatim. `ResultExplanationResponseValidator`
now rejects a `language: "ar"` response whose prose fails the new
`LanguagePurityChecker` (previously only the `language` metadata field was
checked — a response could claim `ar` while being fully English prose and
still pass). `ResultExplanationFallbackFormatter`'s `evidenceExplanation()` now
uses the Arabic label when available instead of always injecting the English
one into an Arabic sentence. `prompt_version` bumped `v1` → `v2` so no
pre-repair cached row is ever served. Root cause and full details:
[docs/localization-integrity-repair.md](localization-integrity-repair.md).

## 2026-08-17 update: Content redesign — Approved Medical Context Catalog

### Why the previous explanation wasn't useful

Both roles' explanations were, in substance, a verbose restatement of KBS output:
`important_findings` produced one isolated paragraph per `conclusion_code`, with no
synthesis across related findings (e.g. a low HGB, low MCV, low MCH/MCHC, and abnormal
RDW — all part of one microcytic-anemia picture — each got its own disconnected
paragraph), no causes, no symptoms, no differential reasoning, and no next steps.
`allowed_medical_context` was permanently `[]`, so Gemini was explicitly told to
discuss no symptoms or causes at all. This redesign's task was to make both roles'
output genuinely answer real questions ("what could this mean, what might cause it,
what symptoms can accompany it, what should I do, when should I move faster" for
Regular; "what's the overall clinical picture, why are these findings connected, what's
the differential, what helps distinguish it" for Student) while keeping every stated
medical fact deterministic, source-grounded, and reviewed — Gemini organizes and
connects; it still never invents.

### Approved Medical Context Catalog

A new, deterministic, versioned, source-grounded content layer:
`backend/resources/medical_context/{cbc,diabetes,liver_function}.json`. Structurally
identical to KBS's own `source_registry.json` convention (reviewed reference data
living in the backend as plain files, not generated at runtime). 23 **context groups**
across the three categories, covering 17 CBC / 4 diabetes / 5 liver-function conclusion
codes (`GENERAL_ANEMIA_CONTEXT`, `MICROCYTIC_HYPOCHROMIC_ANEMIA_CONTEXT`,
`HYPOGLYCEMIA_CONTEXT`, `HEPATOCELLULAR_INJURY_CONTEXT`, etc. — the full list is in the
JSON files themselves). Each group:

```json
{
  "context_group_code": "MICROCYTIC_HYPOCHROMIC_ANEMIA_CONTEXT",
  "conclusion_codes": ["microcytic_hypochromic_anemia_pattern", "..."],
  "superseded_by_group_codes": [],
  "review_status": "APPROVED",
  "patient_friendly_meaning": { "en": "...", "ar": "..." },
  "clinical_relevance": { "en": "...", "ar": "..." },
  "possible_causes": [{ "code": "MICROCYTIC_CAUSE_...", "label": {...}, "description": {...} }],
  "common_symptoms": [{ "code": "...", "text": {...} }],
  "general_next_steps": [{ "code": "...", "text": {...} }],
  "red_flags": [{ "code": "...", "text": {...} }],
  "student_context": {
    "pathophysiology": {...},
    "differential_considerations": [{ "code": "...", "text": {...} }],
    "distinguishing_information": [{ "code": "...", "text": {...} }],
    "learning_points": [{ "code": "...", "text": {...} }]
  },
  "sources": [{ "source_id": "SRC_MEDLINEPLUS_...", "organization": "MedlinePlus (NLM)", "title": "...", "url": "..." }]
}
```

Only groups with `review_status: "APPROVED"` are ever loaded — a `DRAFT`/other status
is silently excluded (verified by a dedicated test), giving this project a real,
if currently unused, path to stage new content for review before it can ever reach
Gemini. Every claim (cause, symptom, next step, red flag, pathophysiology, differential
point) is a concise paraphrase traceable to a `sources` entry citing MedlinePlus (NLM),
NIDDK, CDC, ACG, or AASLD — no large copyrighted passages, and any claim that couldn't
be source-grounded was simply omitted rather than invented. The source list lives
directly in each group's `sources` array, kept next to the content it supports rather
than in a disconnected bibliography that could drift.

**Grouping/supersedes logic**: some conclusion codes are general (e.g. "some anemia
pattern") while a more specific sibling conclusion (e.g. "microcytic anemia pattern")
fires alongside it. `superseded_by_group_codes` lets the general group defer to the
specific one so the same picture isn't explained twice at two levels of detail. A
genuinely separate finding (e.g. thrombocytosis firing alongside a microcytic-anemia
conclusion) is never merged into the anemia group — it gets its own
`THROMBOCYTOSIS_CONTEXT` group and is presented as a separate, clearly-labeled finding,
never with an invented causal link between the two.

**Per-category coverage**: CBC 17 codes across 14 groups (anemia patterns
general/microcytic/normocytic/macrocytic, polycythemia, WBC patterns
infection/neutrophilia/lymphocytosis/low-WBC/neutropenia/eosinophilia, platelet
patterns thrombocytopenia/thrombocytosis, combined cytopenia). DIABETES 4 codes across
4 groups (hypoglycemia, prediabetes, diabetes pattern, discordant glucose). LIVER_
FUNCTION 5 codes across 5 groups (hepatocellular injury — covering the isolated-
ALT/isolated-AST/combined variants together, cholestatic injury, mixed injury,
bilirubin elevation, impaired synthetic function). Any KBS conclusion code with no
matching catalog group simply produces empty arrays for that section (see "Empty-
section behavior" below) rather than blocking the whole explanation — the explanation
still renders with whatever sections do have approved content.

### New backend classes

- **`App\Services\Ai\MedicalContext\ApprovedMedicalContextCatalog`** — loads and
  parses every `*.json` file in the configured catalog directory
  (`config('ai.medical_context.catalog_path')`, default `resource_path('medical_context')`,
  bound as a singleton in `AppServiceProvider`), keeping only `APPROVED` groups.
  `groupsForConclusionCodes()` resolves a set of conclusion codes to their groups,
  deterministically ordered, with supersedes filtering already applied.
- **`App\Services\Ai\MedicalContext\ApprovedMedicalContextResolver`** — the
  per-`Analysis` layer on top of the catalog. `buildLocalizedContext()` picks the
  `ar`/`en` text for every field per the requested language and is what actually goes
  into the Gemini payload's `allowed_medical_context.groups`. `allowedCodes()` returns
  the six-key `{causes, symptoms, next_steps, red_flags, differential, distinguishing}`
  array of context codes the validator allow-lists against — built fresh per analysis,
  so a code from an unresolved group (e.g. a symptom code that belongs to a different
  KBS conclusion this specific analysis didn't fire) is never accepted, even though it
  exists somewhere else in the catalog.

### Schema v2 (`schema_version: "2"`)

```json
{
  "schema_version": "2", "language": "ar", "category": "CBC", "role": "regular",
  "overview": "string",
  "possible_causes": [{ "context_code": "string", "title": "string", "explanation": "string" }],
  "possible_symptoms": [{ "context_code": "string", "text": "string" }],
  "clinical_relevance": "string",
  "next_steps": [{ "context_code": "string", "text": "string" }],
  "red_flags": [{ "context_code": "string", "text": "string" }],
  "limitations": "string",
  "student_context": {
    "pathophysiology": "string",
    "differential_considerations": [{ "context_code": "string", "text": "string" }],
    "distinguishing_information": [{ "context_code": "string", "text": "string" }],
    "learning_takeaway": "string"
  }
}
```

`student_context` is present only when `role === "student"` — `ResultExplanation
PromptBuilder::responseSchema()` builds the Gemini `responseSchema` conditionally, and
`ResultExplanationResponseValidator` requires/rejects the key accordingly. The old
`important_findings`/`missing_information_explanation`/`educational_takeaway` fields
are gone entirely — replaced by the causes/symptoms/next-steps/red-flags shape that
actually answers the task's required questions. `missing_information` is still sent to
Gemini as KBS input context (unchanged) but is no longer a dedicated output field —
role instructions cover it implicitly as part of the overview/limitations framing where
relevant, since the task's required schema doesn't reserve a slot for it.

### Prompt rewrite (`ResultExplanationPromptBuilder`)

`regularInstruction()`/`studentInstruction()` replace the old single role-depth
paragraph with explicit prioritized questions per role (see class docblock for exact
wording), plus new hard rules: never rank a cause as most likely unless the catalog
itself does so, synthesize related findings from the same context group into one
narrative instead of one paragraph per conclusion code, never merge unrelated groups
or invent a causal link between them, only restate a numeric value when it materially
improves understanding, and every `context_code` must reference a supplied
`allowed_medical_context` item or the section must be returned as an empty array —
never filled from Gemini's own knowledge, never a "no data" placeholder sentence.

### Validator rewrite (`ResultExplanationResponseValidator`)

`SCHEMA_VERSION` bumped `'1'` → `'2'`. New `validateCauses()`/`validateCodedItems()`/
`validateStudentContext()` each check every item's `context_code` against the specific
`allowedContextCodes` array the resolver built **for this analysis** — not "exists
anywhere in the catalog." A dedicated test proves this: a real, catalog-authored
`THROMBOCYTOSIS_SYMPTOM_USUALLY_NONE` code (valid content, but belonging to a group
this specific test analysis never resolved) is rejected exactly like an invented code
would be. Bounded-length and `MedicalSafetyPatterns`/`LanguagePurityChecker` checks are
unchanged in spirit, applied per-field across the new shape.

### Two validator false-positive bugs found and fixed during live verification

Live end-to-end verification (real KBS + real Gemini, see below) caught two bugs in
the shared `MedicalSafetyPatterns` keyword net — both were rejecting **legitimate,
catalog-approved content**, not actual violations:

1. **Bare-noun medication block.** The original pattern list forbade the bare words
   `medication`/`antibiotic`/`tablet`/`capsule`/`dosage`/`prescri*` (and Arabic
   `دواء`/`قرص`/`كبسول`/`مضاد حيوي`/`جرعة`/`ملغ`) anywhere in text. But "medication
   effect" is a legitimate, source-grounded **cause** in the catalog for several
   categories (`HEPATOCELLULAR_CAUSE_MEDICATION`, `DIABETES_CAUSE_MEDICATION_INDUCED`,
   `HYPOGLYCEMIA_CAUSE_MEDICATION`, several CBC groups' `*_CAUSE_MEDICATION`/`*_DDX_
   MEDICATION` codes) and "medication review" is a legitimate, safe next step — the
   bare-word ban rejected these outright. Fixed by narrowing the patterns to concrete
   dosage/instruction *signals* instead of the noun itself: a numeric dose
   (`\d+\s*(mg|mcg|milligram)`, `\d+\s*ملغ`), a frequency schedule (`twice daily`,
   `every \d+ hours`, `كل \d+ ساعات`), or an explicit second-person instruction
   (`you should/must/need to take`, `احتاج ان تتناول`, `خذ قرص`, `ابدأ الدواء`). The two
   existing safety-guard tests (`"a Gemini response with a treatment/dosage
   recommendation is rejected..."` in both Phase 4C and Phase 4E) both use a numeric
   dose + frequency phrase and still correctly reject.
2. **Un-negated "confirmed diagnosis" block.** The system prompt requires every
   `limitations` field to state the explanation "does not confirm clinical improvement
   or deterioration" and is "not a medical diagnosis." Gemini's own safe paraphrase of
   that requirement — "does not represent a confirmed diagnosis", "not a confirmed
   diagnosis" — contains the literal substring `confirmed diagnosis`, which the
   original bare-phrase ban rejected regardless of the negation sitting right in front
   of it. Fixed with a small negation-aware check (`MedicalSafetyPatterns::
   hasUnhedgedDiagnosisClaim()`): every `confirmed diagnosis`/`تشخيص مؤكد` occurrence is
   located, and only counted as forbidden if none of a fixed list of negation cues
   ("not a", "is not a", "does not represent a", "ليس", "لا يمثل", …) appear in the 40
   characters immediately before it. An affirmative claim ("this is a confirmed
   diagnosis of...", "you have a confirmed diagnosis") is still correctly rejected; the
   required disclaimer phrasing is not. All other cure/recovery phrases (`cured`, `no
   longer has`, `شُفي`, …) were left unchanged — those are unsafe claims regardless of
   surrounding negation, unlike the diagnosis-hedge case.

Both fixes are scoped to `MedicalSafetyPatterns` only (shared with Phase 4C); no KBS
logic, catalog content, or schema was touched to work around either bug. 11 targeted
before/after cases (numeric-dose rejection, frequency rejection, explicit-instruction
rejection, negated-diagnosis acceptance, affirmative-diagnosis rejection, bare-noun
medication acceptance in both languages) were verified directly against `isSafe()`
before being folded into the full regression run; the full 357-test backend suite,
including the pre-existing Phase 4C dosage/cure safety-guard tests, passed unchanged
after both fixes.

### Fallback rewrite (`ResultExplanationFallbackFormatter`)

No longer falls back to the old rigid KBS-only essay. Builds the full v2 schema
deterministically straight from the resolver's already-localized catalog groups —
`flattenCauses()`/`flattenCodedItems()`/`studentContext()` walk every resolved group's
`possible_causes`/`common_symptoms`/`general_next_steps`/`red_flags`/`student_context`
arrays into the same shape Gemini would have produced, so a Gemini outage or validation
rejection still shows the user genuinely useful, catalog-grounded content — never a
"no explanation available" placeholder and never content Gemini would have had to
invent. Live-verified directly (see below): a real Gemini timeout on
`CBC/student/ar` produced a `FALLBACK` response with the same useful causes/symptoms/
next-steps sections as the `AVAILABLE` responses, just without Gemini's synthesized
prose connecting them.

### Cache versioning

`SCHEMA_VERSION` (`GeminiResultExplainer`) bumped `'1'` → `'2'`. `prompt_version`
default (`config('ai.result_explanation.prompt_version')`) bumped `'v2'` → `'v3'`
(`.env.example`/`README.md` updated to match). Since the cache identity is
`(analysis_id, task_type, language, role, prompt_version, schema_version)`, this
redesign is a different identity on **both** axes — no pre-redesign row (old
`important_findings` shape) can ever be served under the new identity, and old rows are
left untouched in the table rather than migrated or deleted. A historical Report
Details request for an analysis whose only cached explanation predates this redesign
simply misses the cache and generates a fresh v2/v3 explanation on next request — KBS
is never rerun; only the explanation layer regenerates.

### Frontend

`ResultContextualExplanationCard` was rebuilt into short, scannable sections instead of
one dense block — see `src/types/resultExplanation.types.ts` (new `possibleCauses`/
`possibleSymptoms`/`clinicalRelevance`/`nextSteps`/`redFlags`/`studentContext` shape)
and `src/features/analysis/resultExplanationContract.ts` (snake_case → camelCase
mapping). Regular renders "What might this mean? / Possible causes / Possible
symptoms / What to do next / When faster evaluation may be appropriate / Important
note"; Student renders "Clinical Picture / Clinical Relevance / Differential
Considerations / Clinical Manifestations / What Helps Differentiate / Learning
Takeaway" (exact Arabic strings in `src/i18n/ar.ts`, exact English in
`src/i18n/en.ts`). Both roles receive the same backend payload — the schema is not
role-forked — but only the sections relevant to the requesting role are rendered; a
coded-item section with zero approved items (e.g. a category with no red flags in the
catalog yet) renders nothing at all, never a "No data" placeholder. The card remains
the first major section on both the live result screen and historical Report Details,
before every deterministic KBS section, unchanged from the original Phase 4E layout
requirement.

### Live verification results

Real KBS analyses + real Gemini calls (`php artisan tinker`, no mocking) were run for
all three categories × both roles × both languages (12 combinations) using the same
HGB/MCV/MCH/MCHC/RDW/Platelets CBC case the task specified as the required quality
check (HGB 8.9 g/dL low, MCV 71 fL low, MCH 21.7 pg low, MCHC 30.7 g/dL low, RDW 18.2%
high, Platelets 468 elevated). Result: 11 of 12 `AVAILABLE` with genuinely synthesized,
catalog-grounded, properly-hedged content; 1 of 12 (`CBC/student/ar`) `FALLBACK` due to
a transient `GeminiException` (confirmed via `storage/logs/laravel.log` — "Result
explanation generation failed", not "failed strict validation" — i.e. an API-level
failure, not a validator rejection), whose fallback content was independently confirmed
to be equally useful (see above).

The CBC Regular/Arabic case produced one synthesized paragraph connecting HGB/MCV/MCH/
MCHC/RDW into a single microcytic-anemia picture (not "Finding 1 → numbers, Finding 2 →
numbers"), plus a separately-labeled thrombocytosis finding (not merged into the anemia
narrative, no invented causal link), approved causes (iron-deficiency anemia, chronic
disease, thalassemia trait), approved symptoms hedged as "may experience"/"can
sometimes be associated with", general next steps with no medication/dosage
instructions, and a red flag phrased conservatively without implying the user currently
has those symptoms. The Student variant of the same case produced a clinical-teaching
narrative (pathophysiology of iron-restricted erythropoiesis, differential
considerations, distinguishing information such as ferritin/iron studies) genuinely
different in structure and content from the Regular output, not the same paragraph with
heavier vocabulary.

### Documentation of this update

Per the task's explicit scope rule: no new cause, symptom, clinical manifestation,
pathophysiology statement, follow-up suggestion, red flag, or differential point exists
anywhere in this redesign unless it is present in the approved catalog and
source-grounded — Gemini remained the presentation layer throughout, never a source of
new medical fact. No KBS rule condition, threshold, scoring weight, or deterministic
inference was changed; the KBS test suite (186 tests / 252 subtests) passes unchanged.
No Phase 4F work (or any diagnosis/treatment functionality) was started.
