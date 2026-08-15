# Phase 3B — Laravel Quiz Backend Domain (3B.2 persistence + 3B.3 KBS integration)

This document is the operational contract for the full quiz-first slice: persisting a Student-only, MCQ-only quiz session with immutable per-attempt snapshots and server-side scoring (Phase 3B.2), plus real KBS-driven Case-Specific question generation, async preparation, and Final Result locking (Phase 3B.3). It does not include frontend Quiz History UI, Weak Topics/Adaptive Learning, AI-generated content, or Phase 4 Report Comparison — those remain out of scope. The React Native client is now wired to this real API (see `frontend/src/api/quiz.api.ts`); the Phase 3B.1 mock adapter has been removed from the production path.

## Architecture

```text
Authenticated Student mobile client
-> Laravel POST /api/v1/reports/{report}/quiz
-> role + ownership + report-state + category-gate + idempotency checks (synchronous)
-> reuse-or-start an INTERNAL quiz-first Analysis for this exact verified version
   (StartReportAnalysis::handle(..., internal: true) -- same KBS pipeline/idempotency
   direct-result uses; the public /analyze endpoint still rejects flow=quiz-first)
-> if KBS already has a result (warm) or is unreachable/rejects the input: finish
   the quiz SYNCHRONOUSLY in this request (General questions +, when available,
   real Case-Specific questions) -> READY or FAILED
-> otherwise the quiz stays PREPARING; a database-queued ProcessReportAnalysis job
   runs the real KBS call, then dispatches FinalizeQuizPreparation, which builds the
   quiz the same way -> READY or FAILED
-> mobile client polls/answers only via GET /api/v1/quiz/{quiz} and POST /api/v1/quiz/{quiz}/answers
-> Final Result (GET /api/v1/analyses/{analysis}) stays LOCKED (403 QUIZ_RESULT_LOCKED)
   until that exact quiz session reaches COMPLETED
```

Quiz creation has two paths depending on whether KBS already has (or can produce) a result fast enough to answer within the same HTTP request:

- **Warm/synchronous**: the internal Analysis is already `SUCCEEDED`/`FAILED` (a repeat request, or KBS was unreachable so the flow degrades to General-only immediately) — `FinalizeQuizSession` runs inline and the response already carries `status: "READY"` (or `FAILED`).
- **Cold/asynchronous**: the internal Analysis is freshly `QUEUED` — the response carries `status: "PREPARING"` with an empty `questions` array, and the client is expected to poll `GET /quiz/{quiz}` (mirroring the same poll pattern `result-processing.tsx` already used for direct-result). This is the real, observable use of `QuizSessionStatus::Preparing` that Phase 3B.2 only reserved for forward compatibility.

## Dynamic Quiz Size Policy

`config/quiz.php` holds the preferred composition as configuration, not a hard-coded contract:

```php
'preferred_general_count' => (int) env('QUIZ_PREFERRED_GENERAL_COUNT', 14),
'preferred_case_specific_count' => (int) env('QUIZ_PREFERRED_CASE_SPECIFIC_COUNT', 6),
```

Every `quiz_sessions` row stores **both** the target and the actual composition:

| Column | Meaning |
| --- | --- |
| `target_total` / `target_general_count` / `target_case_specific_count` | the preferred policy values captured at creation time |
| `actual_total` / `actual_general_count` / `actual_case_specific_count` | what was really selected/generated for this session |

`actual_total` can legitimately be smaller than `target_total` (e.g. 14 General + 4 Case-Specific = 18, or 14 + 0 = 14 when Case-Specific is unavailable, as in Phase 3B.2). The system never pads with off-category General questions, never duplicates questions, and never fabricates Case-Specific content to reach the target — see `StartQuizSession::buildQuestionSet()`.

## General Question Bank

Table `questions` (model `App\Models\Question`): `category` (`CBC`/`DIABETES`/`LIVER_FUNCTION`, reusing the existing `ReportTestCategory` enum — no second catalog was introduced), `type` (`GENERAL`/`CASE_SPECIFIC`, `QuizQuestionCategory` enum), `question_text_json`/`explanation_json` (bilingual `{"en": ..., "ar": ...}` maps, matching the `title_json`/`summary_json` convention already used by `AnalysisConclusion`), `options_json` (`[{id, text: {en, ar}}]` — options are keyed by a stable string id, never by position/letter, so reordering options never changes which one is "correct"), `correct_option_id`, `active`, `content_version`, `review_status` (`DRAFT`/`APPROVED`).

**No pre-existing quiz/question infrastructure was found anywhere in this backend** (verified by an exhaustive grep across `app`, `database`, `routes`, `tests`, `config`, `docs` for `quiz|question|mcq|correct_answer|explanation|student_answer|quiz_session|question_bank` — every hit was the pre-existing `AnalysisFlow::QuizFirst` handoff placeholder, not real quiz infrastructure). This schema and the Question Bank are new in Phase 3B.2.

**Selection currently filters only on `active = true`**, not `review_status`. `review_status` is tracked as metadata for a future content/review workflow but is deliberately **not yet enforced** as a selection gate, to avoid the columns implying a medical review has happened when it has not. Before any production content is added, wire the selection query in `StartQuizSession::buildQuestionSet()` to also require `review_status = APPROVED`, and treat "Approved" as meaning "cleared by the real content/medical review process" — not merely "usable in dev".

**Production content status: none exists.** The only rows ever created in this phase are (a) Pest test fixtures created via `QuestionFactory` (never persisted outside the test database) and (b) `database/seeders/QuizQuestionBankDevSeeder`, which is gated behind `app()->isLocal()` exactly like the existing demo-user seeder, inserts 5 clearly `[DEV FIXTURE]`-labelled placeholder questions per category, and explicitly documents that populating the real, medically reviewed Question Bank (~14 per category) is a separate content/review task. No medical content was generated from general model knowledge and presented as production-ready.

## Domain model

```text
questions                 - reusable General Question Bank (see above)
quiz_sessions              - one row per quiz attempt; target vs actual counts, status, score
quiz_question_snapshots    - immutable copy of exactly what was shown, one row per question per session
student_answers            - one immutable answer per snapshot, correctness computed server-side
```

All four are new tables (migrations `2026_08_14_0000{1,2,3,4}_*`). `Report::quizSessions()` / `User::quizSessions()` relations were added; no other Phase 2/3A table or model was altered.

### `quiz_question_snapshots` — why a separate table from `questions`

A `QuizQuestionSnapshot` copies `question_text_json`, `options_json`, `option_order_json` (the shuffled display order captured once, at build time), `correct_option_id`, and `explanation_json` directly onto the snapshot row, plus `source_question_id`/`source_question_version` for traceability back to the bank row it came from. Editing or re-versioning a `Question` afterwards — wording, options, correct answer, `content_version` — **never** changes an already-built snapshot; a Pest test (`editing the source question bank does not alter an already built quiz snapshot`) asserts this by mutating the source `Question` after quiz creation and re-reading the snapshot. The same columns exist for a future Case-Specific question (`case_specific_template_id`/`case_specific_template_version`, `evidence_json`, `rule_code`, `analyte_refs_json`) so Phase 3B.3 needs no new snapshot columns.

## Laravel quiz API

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/reports/{report}/quiz` | start (or idempotently return) a quiz session for a verified result set |
| `GET` | `/api/v1/quiz/{quiz}` | resume / poll progress; safe (no unanswered correct answers) representation |
| `POST` | `/api/v1/quiz/{quiz}/answers` | submit one answer; returns correctness + updated session progress |

`POST .../quiz` request:

```json
{ "verified_result_set_id": 3 }
```

Response envelope (`QuizSessionResource`): `id`, `report_id`, `verified_result_set_id`/`version`, `report_category`, `status` (`READY`/`IN_PROGRESS`/`COMPLETED`/`FAILED`), `target`/`actual` (`total`/`general`/`case_specific`), `progress.answered_count`, `score`, `error` (only when `FAILED`), `started_at`/`completed_at`, and `questions[]`.

Each item in `questions[]`: `id`, `sequence`, `category` (`GENERAL`/`CASE_SPECIFIC`), `question` (bilingual map), `options` (`[{id, text}]` in the session's fixed display order), `evidence_label`, and `answered` — `null` until that question has a `StudentAnswer`, then `{selected_option_id, correct, correct_option_id, explanation, answered_at}`.

**Correct answers and explanations are safe by construction**: `correct_option_id`/`explanation` only ever appear nested inside `answered`, which the resource sets to `null` whenever no `StudentAnswer` row exists for that snapshot yet — there is no code path that can leak them before submission. A Pest test asserts the raw JSON response for a freshly created quiz contains neither key at the question level.

`POST .../answers` request:

```json
{ "question_snapshot_id": 42, "selected_option_id": "b" }
```

Response: `{"answer": {selected_option_id, correct, correct_option_id, explanation}, "session": <QuizSessionResource>}` — the client never sends a correctness flag; it is always computed server-side from the immutable snapshot's `correct_option_id`.

### Validation and authorization, in order

**`POST /reports/{report}/quiz`**

1. Sanctum authentication (`401 UNAUTHENTICATED`).
2. Report ownership via the existing `ReportPolicy::update` (`403 FORBIDDEN`) — same convention as `StartAnalysisController`.
3. Caller role is `student` (`403 QUIZ_STUDENT_ONLY`) — Regular users are rejected even if they somehow own a report.
4. `verified_result_set_id` belongs to the report and was confirmed by this user (`422 VERIFIED_RESULT_SET_INVALID`).
5. `verified_result_sets.category_gate_status === 'MATCH'` and matches the report's category (`409 CATEGORY_GATE_NOT_MATCHED`).
6. Report status is `VERIFIED` or later (`409 QUIZ_NOT_PROCESSABLE`).
7. Verified rows are non-empty (`422 VERIFIED_RESULTS_EMPTY`).
8. Idempotency lookup/lock (see below), then General selection + Case-Specific provider call + snapshotting, all inside one DB transaction.

**`GET /quiz/{quiz}` and `POST /quiz/{quiz}/answers`**

1. Sanctum authentication (`401 UNAUTHENTICATED`).
2. Quiz ownership via `QuizSessionPolicy::view` (`403 FORBIDDEN`) — `quiz_sessions.user_id === $user->id`. Knowing a quiz ID alone grants nothing; only a Student can ever own a `quiz_sessions` row in the first place (enforced at creation, check 3 above), so no separate role check is needed post-creation.
3. (answers only) session status is `READY`/`IN_PROGRESS` (`409 QUIZ_SESSION_NOT_ACTIVE`); snapshot belongs to this session (`422 QUIZ_QUESTION_NOT_FOUND`); `selected_option_id` exists in the snapshot's options (`422 QUIZ_OPTION_INVALID`); no prior answer exists for this snapshot (`409 QUIZ_ANSWER_ALREADY_SUBMITTED`).

### Idempotency

Mirrors `StartReportAnalysis`: no client-supplied key. Laravel derives a server-side `identity_key = sha256(report_id | verified_result_set_id | verified_result_set_version | user_id | preferred_general_count | preferred_case_specific_count)`, unique on `quiz_sessions.identity_key`, resolved under `lockForUpdate()` inside a `DB::transaction`. A repeat request (double tap) with the same identity:

- returns the existing session unchanged if its status is anything other than `FAILED` (`200`, no new row, no reshuffled questions — a Pest test asserts the returned `questions[].id` order is byte-identical across repeated calls);
- rebuilds (deletes old snapshots, re-selects) if the prior attempt was `FAILED`, in case the Question Bank has grown since.

The controller returns `201 Created` on `wasRecentlyCreated`, `200 OK` on an idempotent replay — matching the existing `Analysis` controller's `202`/`200` convention, adjusted to `201` since quiz creation is synchronous rather than queued.

### Quiz lifecycle

```text
POST /reports/{report}/quiz
  --(>=1 question selected)--> READY
  --(0 questions selected)--> FAILED (prepare_error_code = QUIZ_NO_ELIGIBLE_QUESTIONS)
READY --(first POST .../answers)--> IN_PROGRESS
IN_PROGRESS --(answered_count === actual_total, never a hard-coded 20)--> COMPLETED (score persisted)
```

Completion is always `answered_count === quiz_sessions.actual_total` for *that* session — an 18-question or 5-question session completes exactly when its own questions are all answered, never waiting for (or capping at) 20. This is asserted directly by a Pest test that builds a 5-question quiz, answers all 5, and checks completion fires at 5.

### Submitted Answer Policy

Answers are **immutable once accepted**. A second submission for the same `quiz_question_snapshot_id` is rejected with `409 QUIZ_ANSWER_ALREADY_SUBMITTED` and does not overwrite the first answer (enforced both by an explicit existence check in `SubmitQuizAnswer::handle()` and by a DB-level `unique(['quiz_session_id', 'quiz_question_snapshot_id'])` constraint on `student_answers` as a second line of defense). This is a deliberate MVP choice, not dictated by any pre-existing convention in the codebase (no prior "answer" concept existed to conflict with).

### Error codes (`error_code` in the JSON envelope)

`VALIDATION_ERROR`, `UNAUTHENTICATED`, `FORBIDDEN`, `QUIZ_STUDENT_ONLY`, `VERIFIED_RESULT_SET_INVALID`, `CATEGORY_GATE_NOT_MATCHED`, `QUIZ_NOT_PROCESSABLE`, `VERIFIED_RESULTS_EMPTY`, `QUIZ_NO_ELIGIBLE_QUESTIONS` (not an HTTP error — a `200`/`201` response with `status: "FAILED"` and this code in `data.error.code`), `QUIZ_SESSION_NOT_ACTIVE`, `QUIZ_QUESTION_NOT_FOUND`, `QUIZ_OPTION_INVALID`, `QUIZ_ANSWER_ALREADY_SUBMITTED`, `QUIZ_RESULT_LOCKED` (403, `GET /analyses/{analysis}` before the quiz completes), `NOT_FOUND`, `INTERNAL_ERROR`, plus every KBS boundary code from `phase-3-analysis.md` (`KBS_SERVICE_UNAVAILABLE`, `KBS_TIMEOUT`, `ANALYSIS_INPUT_INVALID`, ...) since quiz-first now shares the exact same KBS call path as direct-result.

## KBS-driven Case-Specific generation (Phase 3B.3)

`App\Services\Quiz\CaseSpecificQuestionProvider` (interface, unchanged since Phase 3B.2) is now bound to a real implementation:

```php
// AppServiceProvider::register()
$this->app->bind(CaseSpecificQuestionProvider::class, CaseSpecificQuestionBuilder::class);
```

`CaseSpecificQuestionBuilder` (`app/Services/Quiz/CaseSpecificQuestionBuilder.php`) never fabricates a question. Its pipeline, exactly as specified:

```text
load Analysis::ruleTraces() where fired = true
-> for each CaseSpecificTemplate in this report's category (CaseSpecificTemplateCatalog)
-> does the template's trigger rule_code appear among the fired traces?
   no  -> skip (never invent a question for a rule that did not fire)
   yes -> does that trace carry non-empty evidence?
          no  -> skip (never ask an unsupported question)
          yes -> build a draft from the template + this report's real evidence, take up to $limit
```

A `CaseSpecificTemplate` (`app/Services/Quiz/CaseSpecific/CaseSpecificTemplate.php`) is a small, hand-authored, deterministic value object: `id`, `version`, `category`, `ruleCode` (the trigger), bilingual `questionText`/`options`/`explanation` with `{label}`/`{value}`/`{unit}` placeholders, and `correctOptionId`. `buildDraft(array $evidence)` fills the placeholders from the fired rule's own real, per-report evidence (e.g. `{label}` → `"Hemoglobin"`, `{value}` → `9.5`, `{unit}` → `"g/dL"`) and shuffles option order. No LLM is involved anywhere in this pipeline; the only per-report variability is which of a fixed set of already-authored templates match, and the real numbers interpolated into them.

`CaseSpecificTemplateCatalog` (`app/Services/Quiz/CaseSpecific/CaseSpecificTemplateCatalog.php`) currently ships **6 templates**, 2 per category, each verified against the real `kbs/core` engine (not assumed) before being written:

| category | rule_code | conclusion_code | template_id |
| --- | --- | --- | --- |
| CBC | `R001` | `possible_anemia_pattern` | `cbc-cs-r001` |
| CBC | `R002` | `microcytic_anemia_pattern` | `cbc-cs-r002` |
| DIABETES | `R020` | `possible_diabetes_pattern` | `diabetes-cs-r020` |
| DIABETES | `R017` | `possible_hypoglycemia_pattern` | `diabetes-cs-r017` |
| LIVER_FUNCTION | `LIVER_R001` | `hepatocellular_injury_pattern` | `liver-cs-r001` |
| LIVER_FUNCTION | `LIVER_R007` | `isolated_alp_elevation` | `liver-cs-r007` |

This is a small, initial set (the KBS rule catalog has ~50 active rules across the three categories) — like the Phase 3B.2 General Question Bank, expanding template coverage is future content work, not something to silently generate from general model knowledge. A template whose rule never fires for a given Analysis is simply never used; there is no code path that pads Case-Specific count by lowering trigger requirements or inventing content.

`FinalizeQuizSession` (formerly `StartQuizSession::buildQuestionSet()`, extracted so it can run either synchronously or from a queued job — see below) calls this provider exactly the same way Phase 3B.2's stub did; no schema, controller, or resource changed to add real Case-Specific generation.

## Async KBS orchestration

`StartQuizSession::handle()` now reuses `StartReportAnalysis::handle($report, $set, $user, AnalysisFlow::QuizFirst, internal: true)` — the **same** idempotent service, identity-key mechanism, and KBS client the direct-result flow already used in Phase 3A. `internal: true` is a new, narrowly-scoped parameter that only `StartQuizSession` ever passes; the public `POST /reports/{report}/analyze` controller never does, so the existing Phase 3A test asserting `flow=quiz-first` gets `409 ANALYSIS_NOT_PROCESSABLE` from that endpoint is unchanged and still passes.

Three outcomes after that call, all handled inside `StartQuizSession`:

1. **KBS unreachable, or preflight rejects the input** (`ApiException` with status `503` or code `ANALYSIS_INPUT_INVALID`) — caught, swallowed, and treated as "no Case-Specific evidence available"; the quiz still finalizes synchronously as a General-only quiz. Any other exception (a guard `StartQuizSession` already validated) still propagates.
2. **Analysis already `SUCCEEDED`/`FAILED`** (warm — a repeat request, or the branch above) — `FinalizeQuizSession` runs inline, response is `READY`/`FAILED` immediately.
3. **Analysis freshly `QUEUED`** — the quiz stays `PREPARING`; `ProcessReportAnalysis` (the existing Phase 3A job, given one new private method `dispatchPendingQuizFinalizations()`) dispatches `App\Jobs\FinalizeQuizPreparation` after it persists the Analysis result (success **or** failure), which then runs the identical `FinalizeQuizSession` logic. `FinalizeQuizPreparation` is idempotent-safe: it no-ops if the session is no longer `PREPARING` by the time it runs.

## Result Locking (implemented)

`Analysis::isPendingQuizCompletion(): bool` — `true` only when `flow === AnalysisFlow::QuizFirst` **and** no `quiz_sessions` row referencing this `analysis_id` has `status = COMPLETED`. `ShowAnalysisController` checks this immediately after the existing ownership `Gate::authorize('view', ...)` and throws `403 QUIZ_RESULT_LOCKED` before loading/serializing anything. `AnalysisPolicy::view()` itself is untouched (pure ownership, exactly as in Phase 3A) — the lock is a second, independent check, so the two 403 reasons ("not yours" vs "not unlocked yet") stay distinguishable by `error_code`.

This cannot be bypassed by calling the endpoint directly: the internal quiz-first `Analysis` row is real and persisted (Case-Specific generation needs it), but `flow: 'quiz-first'` gives it a **different `identity_key`** than any `flow: 'direct-result'` analysis for the same verified version (identity already included `flow` since Phase 3A) — so the mobile client's existing "view result without quiz" escape hatch and "View Final Result" button, which both call `POST /analyze` with `flow: 'direct-result'` exactly as in Phase 3A, always create/reuse a **separate, never-locked** Analysis row and are completely unaffected by an incomplete quiz-first attempt sitting alongside it for the same report. Verified live (see below): the internal analysis returned `403 QUIZ_RESULT_LOCKED` before the quiz was answered, and `200 OK` with real conclusions immediately after the 16th (real, dynamic — not 20th) answer completed it.

## Configuration

Backend `.env` (`config/quiz.php`):

```env
QUIZ_PREFERRED_GENERAL_COUNT=14
QUIZ_PREFERRED_CASE_SPECIFIC_COUNT=6
```

No new KBS configuration was needed — quiz-first reuses `config/kbs.php` exactly as direct-result does.

## Verified test run (2026-08-14)

`php artisan test` (full suite, real MySQL `lablearn_testing` database): **165/165 passing, 729 assertions** — the 24 Phase 3B.2 tests (updated to route every quiz creation through `Http::fake` KBS metadata/validate/analyze responses, since quiz creation now always attempts the KBS pipeline) plus 13 new Phase 3B.3 tests in `tests/Feature/Phase3B/QuizKbsIntegrationTest.php`: real per-category Case-Specific template matching (CBC/DIABETES/LIVER_FUNCTION, using response bodies captured from the actual `kbs/core` engine, not invented), KBS-unavailable and preflight-rejected fallback to a General-only quiz, Result Locking before/after completion, direct-result independence from an incomplete quiz-first analysis, the public `/analyze` endpoint's unchanged `quiz-first` rejection, and async `PREPARING` → queued-job → `READY` finalization. `vendor/bin/pint --dirty` clean. `kbs` test suite (`python -m unittest discover -s tests`, `.venv`): **162/162 passing, unchanged** — no KBS/medical rule file was modified.

## Live end-to-end verification (2026-08-14)

Ran the real, non-mocked stack together — `php artisan serve`, a real `php artisan queue:work` against the `database` queue driver, and the real `kbs/api` FastAPI service on `127.0.0.1:8601` — and drove a full quiz-first CBC session over HTTP with `curl`, using the same Hemoglobin-9.5/MCV-72 verified values confirmed earlier to trigger real KBS rules `R001`+`R002`:

1. `POST /reports/{report}/quiz` → `201`, `status: "PREPARING"`, `questions: []` (real `ProcessReportAnalysis` job queued).
2. Queue worker log showed `ProcessReportAnalysis .. DONE` then `FinalizeQuizPreparation .. DONE` (the real async chain, not a test double).
3. `GET /quiz/{id}` → `status: "READY"`, `actual: {total: 16, general: 14, case_specific: 2}` — the two real Case-Specific questions (`cbc-cs-r001`, `cbc-cs-r002`) with `evidence_label: "Hemoglobin 9.5 g/dL"` interpolated from the real KBS response.
4. `GET /analyses/{id}` before answering → `403 QUIZ_RESULT_LOCKED`.
5. All 16 real questions answered via `POST /quiz/{id}/answers`; the session reached `COMPLETED` exactly at the 16th answer (never waiting for/assuming 20), with a real server-computed score.
6. `GET /analyses/{id}` after completion → `200 OK`, `flow: "quiz-first"`, real conclusion `microcytic_anemia_pattern` / rule `R002`.
7. `POST /analyze` with `flow: "quiz-first"` on the same report → still `409 ANALYSIS_NOT_PROCESSABLE` (Phase 3A guard unchanged); `POST /analyze` with `flow: "direct-result"` on the same report → still `202 Accepted` immediately, unaffected by the incomplete/complete quiz-first analysis alongside it.

All services were stopped cleanly after verification.
