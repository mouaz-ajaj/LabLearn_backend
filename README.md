# LabLearn Backend

Laravel REST API for the LabLearn mobile application. It is the single point of contact
for the React Native client — the client never talks to OCR or the KBS (deterministic
expert system) directly. This backend owns authentication, report/OCR orchestration,
verified-result versioning, KBS-backed analysis, and the Student Quiz domain (both the
KBS-generated General Question Bank and per-report Case-Specific questions).

LabLearn is an **educational** tool. See [Medical Safety](#medical-safety).

## Contents

- [Current Project Status](#current-project-status)
- [Technology Stack](#backend-technology-stack)
- [Architecture Overview](#architecture-overview)
- [Repository Structure](#repository--backend-structure)
- [Environment Setup](#environment-setup)
- [Database Setup](#database-setup)
- [Authentication and Authorization](#authentication-and-authorization)
- [Reports / OCR Domain](#reports--ocr-domain)
- [OCR Integration](#ocr-integration)
- [Verification / Versioning](#verification--versioning)
- [KBS Integration](#kbs-integration)
- [Analysis Flow](#analysis-flow)
- [Quiz Backend](#quiz-backend)
- [Quiz History + Statistics](#quiz-history--statistics)
- [Role-Aware AI Result Explanation](#role-aware-ai-result-explanation)
- [General Question Bank](#general-question-bank)
- [Question Bank Refresh Command](#question-bank-refresh-command)
- [Case-Specific Questions](#case-specific-questions)
- [Result Gating](#result-gating)
- [Report History](#report-history)
- [Report Details](#report-details)
- [Comparison + AI Contextualization](#comparison--ai-contextualization)
- [API Endpoints](#api-endpoints)
- [Error Contract](#error-contract)
- [Queues and Jobs](#queues-and-jobs)
- [Artisan Commands](#artisan-commands)
- [Startup / Shutdown](#startup--shutdown)
- [Tests](#tests)
- [Security](#security)
- [Medical Safety](#medical-safety)
- [Known Limitations / Deferred Work](#known-limitations--deferred-work)

See also: [frontend/README.md](../frontend/README.md).

## Current Project Status

| Phase | Status |
|---|---|
| Phase 1 — Authentication (Sanctum, roles, password recovery, profile) | Implemented |
| Phase 2 — Authenticated report upload, OCR, verified-result versioning | Implemented |
| Phase 3A — Direct-result KBS analysis integration | Implemented |
| Phase 3B.1 — Student Quiz frontend UX | Implemented |
| Phase 3B.2 — Quiz backend/domain (sessions, snapshots, answers) | Implemented |
| Phase 3B.3 — KBS Case-Specific questions + result locking + mobile integration | Implemented |
| Phase 3B.4 — KBS-driven General Question Bank generator | Implemented |
| Phase 4A — Dashboard Recent Reports + paginated Report History listing | Implemented |
| Phase 4B — Historical Report Details (verified values + stored KBS result) | Implemented |
| Phase 4C — Multi-report Comparison + AI contextualization | Implemented |
| Phase 4D — Student Quiz History + real Dashboard quiz statistics | Implemented |
| Phase 4E — Role-aware AI result explanation | Implemented |

## Backend Technology Stack

- **PHP 8.3+**, **Laravel 13** (`laravel/framework ^13.8`)
- **Laravel Sanctum** (`^4.3`) — bearer-token API authentication
- **MySQL 8+** — primary datastore (a separate `lablearn_testing` database is used for tests)
- **Database queue driver** (`QUEUE_CONNECTION=database`) — OCR extraction and KBS analysis run as queued jobs
- **Pest 4** (`pestphp/pest`, `pestphp/pest-plugin-laravel`) — the test framework (all tests are Pest, not PHPUnit-style classes)
- **Laravel HTTP client** (`Illuminate\Support\Facades\Http`) — used for all OCR and KBS FastAPI calls
- **Laravel Pint** — code style
- Dev tooling: `laravel/pail`, `laravel/boost`, `nunomaduro/collision`, `mockery/mockery`, `fakerphp/faker`

## Architecture Overview

```text
React Native (Expo)
       |
       v
Laravel REST API  (/api/v1/*)
   |        |            |
   v        v            v
 MySQL   Queue Worker   internal HTTP clients
             |             |         |
             v             v         v
     ProcessReportOcr   OCR FastAPI  KBS FastAPI
     ProcessReportAnalysis  (port 9001)  (port 8601)
```

The mobile app only ever calls the Laravel API. OCR (FastAPI, port `9001`) and the KBS
JSON API (FastAPI, port `8601`) are internal services reachable only from the Laravel
host; the KBS API is bound to `127.0.0.1` and is never exposed on the LAN or through a
firewall rule (see [start-lablearn.ps1](../start-lablearn.ps1)). Laravel authenticates to
both internal services with a per-service API key header (`X-Internal-OCR-Key`,
`X-Internal-KBS-Key`), never forwarded to or accepted from the mobile client.

The KBS itself (`kbs/`) is a deterministic, rule-based Python expert system — not an LLM
and not an AI model. It classifies verified lab values against fixed rules and returns
structured conclusions and rule traces. AI is not used anywhere in the medical inference
path.

## Repository / Backend Structure

```text
app/
  Console/Commands/     ocr:health, kbs:health, quiz:refresh-general-bank
  Enums/                ReportStatus, ReportTestCategory, AnalysisStatus, QuizSessionStatus, ...
  Http/Controllers/Api/ Auth/, User/, Report/, Job/, Analysis/, Quiz/ (single-action controllers)
  Http/Requests/        Form Request validation, grouped the same way as controllers
  Http/Resources/       API Resource response transformers
  Jobs/                 ProcessReportOcr, ProcessReportAnalysis, FinalizeQuizPreparation
  Models/                13 Eloquent models
  Policies/              ReportPolicy, ExtractionJobPolicy, AnalysisPolicy, QuizSessionPolicy
  Services/
    AuthService.php
    Reports/             ReportService
    Ocr/                 OcrClient, OcrResultMapper, OcrException
    Kbs/                 KbsClient, KbsRequestMapper, KbsResponseValidator, KbsException
    Verification/        VerifyReport, CategoryGate
    Analysis/             StartReportAnalysis
    Quiz/                 StartQuizSession, SubmitQuizAnswer, FinalizeQuizSession,
                          CaseSpecificQuestionProvider (interface), CaseSpecificQuestionBuilder,
                          NullCaseSpecificQuestionProvider
      CaseSpecific/       CaseSpecificTemplate, CaseSpecificTemplateCatalog
      GeneralQuestions/   KbsKnowledgeBase, GeneralQuestionGenerator, GeneralQuestionValidator,
                          GeneratedGeneralQuestion, DeterministicSelector, CategoryDisplayNames
        Kbs/               KBS-JSON parsing DTOs (KbsAnalyte, KbsRule, KbsRuleTrigger,
                            LiverRuleTriggerCatalog, ConditionNameCatalog, ConditionPhraseRenderer)
        TemplateFamilies/  13 template family classes + shared traits
config/                  app, auth, database, queue, cors, ocr, kbs, quiz, lablearn, ...
database/
  migrations/            21 migrations
  factories/             model factories used by tests
  seeders/                DatabaseSeeder, QuizQuestionBankDevSeeder ([DEV FIXTURE] placeholders)
tests/
  Feature/                Auth/, Phase2/, Phase3/, Phase3B/, GeneralQuestionBank/, Seeder/, User/
  Unit/                   Phase2/, Phase3/
docs/                     phase-1-api.md, phase-2-ocr.md, phase-3-analysis.md,
                          phase-3b-quiz.md, general-question-bank.md
```

There is no `app/Http/Middleware/` directory — the API uses only framework-provided
middleware (`auth:sanctum`, named `throttle:*` limiters) applied directly in
`routes/api.php`.

## Environment Setup

Requirements: PHP 8.3+, Composer, MySQL 8+.

```bash
composer install
copy .env.example .env
php artisan key:generate
copy .env.testing.example .env.testing
php artisan key:generate --env=testing
```

Create two MySQL databases, `lablearn` and `lablearn_testing`, and set their credentials
in `.env` / `.env.testing`.

Key environment variables (see `.env.example` for the full, placeholder-only file):

```env
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://localhost:8081
CORS_ALLOWED_ORIGINS=http://localhost:8081,http://127.0.0.1:8081
LABLEARN_TOKEN_NAME=mobile
LABLEARN_DEMO_PASSWORD=

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=lablearn

QUEUE_CONNECTION=database

# Internal OCR service (never exposed to the mobile app)
OCR_SERVICE_BASE_URL=http://127.0.0.1:9001
OCR_SERVICE_API_KEY=your-local-secret

# Internal KBS JSON API (never exposed to the mobile app)
KBS_SERVICE_BASE_URL=http://127.0.0.1:8601
KBS_SERVICE_API_KEY=your-local-secret

# General Question Bank (Phase 3B.4)
QUIZ_KBS_KNOWLEDGE_BASE_PATH=            # default: ../kbs/knowledge_base (sibling repo layout)
QUIZ_REFRESH_GENERAL_BANK_ON_START=false # dev launcher sets this to true, see below
QUIZ_REQUIRE_APPROVED_GENERAL_QUESTIONS=false
QUIZ_PREFERRED_GENERAL_COUNT=14
QUIZ_PREFERRED_CASE_SPECIFIC_COUNT=6

# Gemini AI Contextualization for report comparison (Phase 4C) — optional; safe with no key
AI_COMPARISON_CONTEXT_ENABLED=true
GEMINI_SERVICE_BASE_URL=https://generativelanguage.googleapis.com
GEMINI_MODEL=gemini-3.7-flash
GEMINI_API_KEY=                          # empty = deterministic fallback only, comparisons still work
GEMINI_SERVICE_TIMEOUT_SECONDS=20
GEMINI_SERVICE_CONNECT_TIMEOUT_SECONDS=5
GEMINI_SERVICE_RETRY_ATTEMPTS=2
GEMINI_MAX_OUTPUT_TOKENS=2048

# Phase 4E - role-aware result explanation (separate feature gate; also requires
# AI_COMPARISON_CONTEXT_ENABLED=true and a real GEMINI_API_KEY above)
AI_RESULT_EXPLANATION_ENABLED=true
RESULT_EXPLANATION_PROMPT_VERSION=v3
```

Never commit a real `GEMINI_API_KEY`. See
[docs/phase-4c-comparison.md](docs/phase-4c-comparison.md) and
[docs/phase-4e-result-explanation.md](docs/phase-4e-result-explanation.md) for the full configuration,
safety, and fallback contract.

`start-lablearn.ps1` auto-generates random `OCR_SERVICE_API_KEY` / `KBS_SERVICE_API_KEY`
values into `backend/.env` on first run if they are blank — never commit real values for
either.

For optional local demo accounts, set a strong `LABLEARN_DEMO_PASSWORD` and run
`php artisan db:seed`.

## Database Setup

```bash
php artisan migrate
```

**Do not run `php artisan migrate:fresh` / `migrate:reset` / `db:wipe`** against a
database that holds real quiz history — these drop and recreate tables, which destroys
`quiz_sessions`, `quiz_question_snapshots`, and `student_answers` history. Preserving
that history across schema and content changes is a deliberate design requirement (see
[General Question Bank](#general-question-bank)).

## Authentication and Authorization

- **Laravel Sanctum**, personal-access-token style bearer tokens (no cookies/SPA mode).
  Clients send `Authorization: Bearer <token>` on every authenticated request.
- Two registered roles: `regular` and `student` (`App\Enums\UserRole`). Guest is
  unauthenticated and has no database record or token.
- Ownership and role rules are enforced with Policies (`ReportPolicy`,
  `ExtractionJobPolicy`, `AnalysisPolicy`, `QuizSessionPolicy`) and explicit role checks
  inside services (e.g. only `student` accounts may start a quiz —
  `QUIZ_STUDENT_ONLY`).
- Frontend capability flags (see `frontend/README.md`) only control what the UI *offers*;
  every capability they represent is independently re-checked server-side. The frontend
  is never the security boundary.

## Reports / OCR Domain

Report lifecycle (`App\Enums\ReportStatus`): `UPLOADED → QUEUED → PROCESSING →
NEEDS_REVIEW → VERIFIED → ANALYZED → COMPLETED`, with `FAILED` reachable from the queued
states. Category is one of `App\Enums\ReportTestCategory`: `CBC`, `DIABETES`,
`LIVER_FUNCTION`.

1. `POST /reports` creates a `Report` (category, source type, optional patient
   context).
2. `POST /reports/{report}/files` uploads the source file (PDF/PNG/JPG/JPEG, size and
   MIME validated server-side regardless of any client-side check).
3. `POST /reports/{report}/process` creates an `ExtractionJob` and dispatches
   `ProcessReportOcr` onto the database queue.
4. `GET /jobs/{job}` is polled by the client until the job reaches `SUCCEEDED`/`FAILED`.
5. `GET /reports/{report}/extracted-results` returns the raw OCR rows for review.

Raw OCR output (`extracted_results`) is never silently overwritten by a later step —
verification (`POST /reports/{report}/verification`) creates a new, separate,
**versioned** `verified_result_sets` row instead of mutating the raw rows.

## OCR Integration

```text
Laravel  →  App\Jobs\ProcessReportOcr  →  App\Services\Ocr\OcrClient  →  OCR FastAPI
```

- `OcrClient::analyze()` streams the uploaded file to `OCR_SERVICE_BASE_URL` +
  `OCR_SERVICE_ANALYZE_ENDPOINT` (default `/api/v1/ocr/analyze`) with header
  `X-Internal-OCR-Key: <OCR_SERVICE_API_KEY>` and a request id; `OcrClient::health()`
  calls `OCR_SERVICE_HEALTH_ENDPOINT` (`/health`, unauthenticated).
- Supported input types: PDF, PNG, JPG, JPEG, up to `OCR_SERVICE_MAX_UPLOAD_BYTES`
  (default 20 MiB).
- `ProcessReportOcr` runs on the database queue (`ShouldQueue`, `ShouldBeUnique` per
  extraction job, `tries=3`, `timeout=240s`, backoff `[5s, 30s, 90s]`); on a
  non-retryable failure the `ExtractionJob` and `Report` are marked `FAILED` with a
  sanitized `error_code`, never a raw exception message.
- Full protocol detail: [docs/phase-2-ocr.md](docs/phase-2-ocr.md).

## Verification / Versioning

```text
raw extracted_results (OCR)
        |
        v
editable review draft (client-side, not persisted until confirmed)
        |
        v  POST /reports/{report}/verification
verified_result_sets  (version 1, 2, 3, ... per report)
        |
        v
verified_results  (the frozen row values for that version)
```

Each `verified_result_sets` row is versioned per report and records a category-gate
result (`MATCH` / `AMBIGUOUS` / `MISMATCH`), the confirming user, patient context, and an
idempotency key so a duplicate confirm request is safe to retry. Every downstream
`Analysis` and `QuizSession` stores the exact `verified_result_set_id` **and**
`verified_result_set_version` it was built from, so a later re-verification (a new
version) never changes the meaning of an already-completed analysis or quiz.

## KBS Integration

```text
Laravel  →  App\Services\Kbs\KbsClient  →  internal KBS FastAPI (port 8601)  →  deterministic KBS core (Python)
```

- `KbsClient` calls three JSON endpoints under `KBS_SERVICE_BASE_URL`:
  `/v1/validate` (preflight input check), `/v1/analyze` (the analysis itself), and
  `/v1/metadata` (supported categories/schema versions); `/health` is unauthenticated.
- Every call sends `X-Internal-KBS-Key: <KBS_SERVICE_API_KEY>` (except `/health`) and
  retries retryable failures up to `KBS_SERVICE_RETRY_ATTEMPTS` (default 2) times.
- The KBS is **deterministic and rule-based**, not an AI model — the same verified input
  always produces the same conclusions and rule traces. Rules themselves live entirely
  in the Python KBS (`kbs/knowledge_base/*.json`, `kbs/core/`); Laravel never edits or
  duplicates medical rule logic, only reads structured KBS knowledge for question
  generation (see [General Question Bank](#general-question-bank)).
- Supported categories: `CBC`, `DIABETES`, `LIVER_FUNCTION`.

## Analysis Flow

```text
App\Services\Analysis\StartReportAnalysis   (validation, idempotency, dispatch)
        |
        v
App\Jobs\ProcessReportAnalysis              (calls KbsClient::analyze(), persists results)
        |
        v
App\Models\Analysis  +  AnalysisConclusion (per conclusion)  +  RuleTrace (per rule)
```

- `StartReportAnalysis::handle()` checks report ownership, the category gate, and report
  status; calls `KbsClient::metadata()` and `KbsClient::validate()` as a synchronous
  preflight (blocking issues return `ANALYSIS_INPUT_INVALID`, 422); computes a
  deterministic `identity_key` (hash of report id, verified-set id/version, flow, and
  ruleset version) so re-requesting the same analysis reuses the same `Analysis` row
  instead of creating a duplicate; dispatches `ProcessReportAnalysis` after the
  transaction commits.
- `ProcessReportAnalysis` (`ShouldQueue`, unique per analysis id, `tries=3`,
  `timeout=75s`) calls the KBS, persists `AnalysisConclusion`/`RuleTrace` rows inside a
  transaction, and sets `Analysis.status` to `SUCCEEDED` or `FAILED`
  (`App\Enums\AnalysisStatus`).
- `App\Enums\AnalysisFlow` distinguishes `direct-result` (Regular users, immediate
  result) from `quiz-first` (Student users — the same analysis pipeline runs
  internally, but the result stays locked until the quiz is completed; see
  [Result Gating](#result-gating)).
- On completion (success or failure), any `QuizSession` waiting on this analysis is
  finalized via the `FinalizeQuizPreparation` job.
- `GET /analyses/{analysis}` is idempotent and safe to poll repeatedly.

## Quiz Backend

```text
questions                    (reusable Question Bank — General + Case-Specific templates draw from it)
   | selected at quiz creation time
   v
quiz_sessions                (one row per report analysis attempt by a student)
   |
   v
quiz_question_snapshots      (frozen copy of each selected question's text/options/answer/explanation)
   |
   v
student_answers              (one immutable answer per snapshot)
```

`questions` is the **mutable, reusable** bank. `quiz_question_snapshots` is an immutable
**copy** taken at quiz-creation time — this is a deliberate architectural decision: a
later change to (or removal of) a `questions` row must never alter what a student sees
when resuming or reviewing a quiz they already started. See
[Question Bank Refresh Command](#question-bank-refresh-command) for how this is enforced
during a bank refresh.

- `App\Enums\QuizSessionStatus`: `PREPARING`, `READY`, `IN_PROGRESS`, `COMPLETED`,
  `FAILED`.
- `StartQuizSession` (student-only, `QUIZ_STUDENT_ONLY` otherwise) validates the verified
  result set and category gate, and internally reuses `StartReportAnalysis` with
  `flow=quiz-first`. If the underlying analysis is already finished it finalizes the
  quiz synchronously; otherwise the session stays `PREPARING` until
  `FinalizeQuizPreparation` runs.
- `FinalizeQuizSession` selects up to `config('quiz.preferred_general_count')` (14)
  active `GENERAL` questions for the report's category, plus up to
  `config('quiz.preferred_case_specific_count')` (6) `CASE_SPECIFIC` questions from
  `CaseSpecificQuestionProvider`. Actual counts (`actual_general_count`,
  `actual_case_specific_count`, `actual_total`) always reflect what was really
  available — never padded to hit the target. The session becomes `READY` if
  `actual_total > 0`, otherwise `FAILED` with `prepare_error_code =
  QUIZ_NO_ELIGIBLE_QUESTIONS`.
- `SubmitQuizAnswer` rejects an inactive session (`QUIZ_SESSION_NOT_ACTIVE`, 409), an
  unknown snapshot (`QUIZ_QUESTION_NOT_FOUND`, 422), an invalid option
  (`QUIZ_OPTION_INVALID`, 422), or a duplicate answer for the same question
  (`QUIZ_ANSWER_ALREADY_SUBMITTED`, 409 — also enforced by a DB unique constraint on
  `[quiz_session_id, quiz_question_snapshot_id]`). The session moves `READY →
  IN_PROGRESS` on the first answer and to `COMPLETED` (with `score` = number of correct
  answers) once every selected question has been answered.

Full protocol detail: [docs/phase-3b-quiz.md](docs/phase-3b-quiz.md).

## General Question Bank

General questions are reusable and **not** tied to any single patient's current report
values — they are generated once from the structured knowledge already encoded in the
KBS, and then reused across many students/quizzes for the same category.

```text
kbs/knowledge_base/*.json  (read directly from disk, no HTTP call)
        |
        v
KbsKnowledgeBase            (parses analytes, panels, rules into typed PHP DTOs)
        |
        v
13 Template Family classes  (each decides, per KBS entity, whether a meaningful
                              question can honestly be built — never forces one)
        |
        v
GeneralQuestionValidator    (per-question, then whole-bank validation)
        |
        v
GeneralQuestionGenerator    (orchestrates, deduplicates, sorts deterministically)
        |
        v
php artisan quiz:refresh-general-bank   (the only place this ever writes to the DB)
        |
        v
questions table (source = KBS_GENERATED)  →  FinalizeQuizSession selects up to 14/category (unchanged)
```

This is deliberately **not** an LLM pipeline — every question, option, and explanation is
built from a fixed set of bilingual sentence templates filled in with real KBS entities
(analyte names, panel membership, rule conditions, condition names), and distractors are
picked deterministically (`DeterministicSelector`, `md5(seed|candidate)` ranking — never
`shuffle()`/`array_rand()`) from other real KBS entities of the same kind. Regenerating
from an unchanged KBS knowledge base and unchanged `generator_version` always produces
the same bank — "refresh" means *rebuild deterministically*, not *reword randomly*.

**Implemented Template Families** (`app/Services/Quiz/GeneralQuestions/TemplateFamilies/`):
Panel Membership, Abbreviation, Alias Recognition, Required Inputs, Optional Supporting
Inputs, Rule Input Recognition, Pattern Condition Recognition, Status Classification,
Derived Value Inputs, Cross-Panel Relationship, Rule Conclusion Matching, Missing
Supporting Information, Category Comparison (13 total). Each family only produces a
question where the underlying KBS data genuinely supports one defensible correct answer;
a category with no applicable data for a given family simply contributes nothing from
that family.

**Traceability** — every generated `questions` row carries: `source =
KBS_GENERATED`, `source_type` (`ANALYTE`/`PANEL`/`RULE`/`DERIVED_VALUE`/
`CLASSIFICATION`/`RELATIONSHIP`), `source_id`, `template_family`, `generator_version`,
and a unique `stable_source_key` used both for idempotent re-generation and duplicate
prevention.

**Review status** — generated questions are stored with `review_status =
GENERATED_PENDING_REVIEW` (`App\Enums\QuestionReviewStatus`), never `APPROVED` — they
are **not** automatically medically reviewed. Setting
`QUIZ_REQUIRE_APPROVED_GENERAL_QUESTIONS=true` restricts quiz selection to `APPROVED`
questions only, without any code change, once a real review workflow exists.

The current generated bank size (from the most recent local refresh) is documented in
[docs/general-question-bank.md](docs/general-question-bank.md) — treat any specific count
there as a snapshot, not a guarantee, since it depends on the current content of
`kbs/knowledge_base/`. As of that document: CBC 195, DIABETES 61, LIVER_FUNCTION 104,
total 360.

Full design detail (why KBS and not an LLM, per-family rules, distractor/bilingual/
explanation strategy, duplicate detection): [docs/general-question-bank.md](docs/general-question-bank.md).

## Question Bank Refresh Command

```bash
php artisan quiz:refresh-general-bank            # only runs if the config below is enabled
php artisan quiz:refresh-general-bank --force    # runs regardless of config
```

Behavior:

1. Loads the current KBS knowledge base from disk and generates the full candidate
   question set in memory.
2. Validates the entire generated collection **before** touching the database; on any
   validation failure it aborts and prints the errors — the existing bank in the
   database is left untouched.
3. Opens a database transaction, deletes only rows where `source = 'KBS_GENERATED'` and
   rows whose English question text starts with `[DEV FIXTURE]` (the placeholder
   content originally seeded by `QuizQuestionBankDevSeeder`), inserts the newly
   generated bank, then commits.
4. Any other manually authored question (any row that is neither `KBS_GENERATED` nor a
   `[DEV FIXTURE]` row) is left untouched.
5. Asserts that `quiz_sessions`, `quiz_question_snapshots`, and `student_answers` are
   unchanged by the refresh — an already-started or already-completed quiz keeps
   showing exactly the question/options/answer/explanation it was built with, even if
   its source `questions` row is later removed from the bank.
6. If the database replacement step itself fails, the whole transaction rolls back — no
   partial bank is ever left in place.

**Production safety**: the command only runs its destructive replacement step when
`QUIZ_REFRESH_GENERAL_BANK_ON_START=true` (default `false`) or `--force` is passed. The
local development launcher (`start-lablearn.ps1`) sets this env var to `true` on every
run, since a dev database's question content should always match the current KBS/
generator code; a production deployment should leave it unset so a normal process
restart never silently rebuilds question content.

## Case-Specific Questions

Case-Specific questions are generated from the **current report's** fired KBS rules —
architecturally separate from the General Question Bank above.

```text
Analysis  →  fired RuleTrace rows
        |
        v
CaseSpecificQuestionProvider (interface)  →  CaseSpecificQuestionBuilder
        |
        v
CaseSpecificTemplateCatalog  (matches template.rule_code against fired RuleTrace rows)
        |
        v
quiz_question_snapshots (question_category = CASE_SPECIFIC)
```

`CaseSpecificQuestionBuilder` only returns a question when its template's rule actually
fired with non-empty evidence for this analysis — it never fabricates a question to reach
the target count. **Current coverage is intentionally small**: 6 templates covering 6
distinct rule codes (2 per category — `R001`/`R002` for CBC, `R020`/`R017` for DIABETES,
`LIVER_R001`/`LIVER_R007` for LIVER_FUNCTION), out of roughly 50+ active KBS rules across
the three categories. Expanding this coverage is future content work, not something this
README should present as complete.

## Result Gating

```text
Analysis.flow == quiz-first  AND  no QuizSession with status=COMPLETED references it
        → GET /analyses/{analysis} returns 403 QUIZ_RESULT_LOCKED

Analysis.flow == quiz-first  AND  a COMPLETED QuizSession references it
        → GET /analyses/{analysis} returns the result normally

Analysis.flow == direct-result
        → never locked
```

The lock is enforced server-side, in `ShowAnalysisController`, on the only read path for
an analysis — it is not a client-side-only restriction.

## Quiz History + Statistics

`GET /students/me/quiz-history` (Phase 4D) is Student-only (`403 QUIZ_STUDENT_ONLY`,
same convention `StartQuizSession` already uses) and reads exclusively from the
already-persisted `quiz_sessions`/`quiz_question_snapshots`/`student_answers` tables —
no new table, no migration. Only `status = COMPLETED` sessions count; `PREPARING`/
`READY`/`IN_PROGRESS`/`FAILED` sessions are excluded from both the paginated list and
the statistics.

```text
GET /students/me/quiz-history?page=&per_page=&test_category=
-> { data: { summary: {completed_quizzes, correct_answers, total_questions, overall_percentage},
             items: [{id, report_id, test_category, status, completed_at, started_at,
                      score, total, percentage, general_count, case_specific_count}],
             pagination: {current_page, per_page, total, last_page, has_more} } }
```

`summary.overall_percentage` is a **weighted** figure —
`round(SUM(score) / SUM(actual_total) * 100, 1)` across every completed session,
computed by one SQL aggregate query — never an average of each quiz's own percentage
(a 15-question quiz and a 20-question quiz are not weighted equally in a simple
average). It is `null`, never `0`, when the student has completed zero quizzes.
`summary` always reflects **all** completed quizzes regardless of the `test_category`
filter applied to `items` — the filter only narrows the list.

Full question/answer review for one completed quiz **reuses the existing**
`GET /quiz/{quiz}` endpoint (`ShowQuizController`/`QuizSessionResource`) completely
unchanged — it already safely returns every snapshot's question/options in their
original order plus, once answered, the student's answer, the correct answer,
correctness, and explanation. No second detail endpoint was created. `content_version`
bumps or `active=false` changes to a `Question` row, or a full
`quiz:refresh-general-bank` run, never alter an already-built `QuizQuestionSnapshot`.

Full design rationale, the exact score/weighting semantics, and live-verification
results: [docs/phase-4d-quiz-history.md](docs/phase-4d-quiz-history.md).

## Role-Aware AI Result Explanation

`POST /analyses/{analysis}/explanation` (Phase 4E) adds an optional, role-aware AI
explanation layer on top of an already-succeeded deterministic `Analysis` — Gemini
never computes a medical fact; it only organizes and explains conclusions KBS already
produced, drawing exclusively on a deterministic, source-grounded **Approved Medical
Context Catalog** (`backend/resources/medical_context/*.json`, reviewed
causes/symptoms/next-steps/red-flags/differential content keyed by conclusion code),
at a depth matched to the requesting user's role: `regular` gets a synthesized
plain-language picture (possible causes, possible symptoms, general next steps, red
flags), `student` additionally gets pathophysiology, differential considerations, and
distinguishing information. Every cause/symptom/next-step/red-flag/differential item
Gemini outputs must reference a `context_code` actually supplied for that specific
analysis — Gemini organizes and connects catalog content into a coherent narrative but
never invents a new one. Reuses Phase 4C's `GeminiClient` and shared
`config('ai.gemini.*')` connectivity settings unchanged; everything task-specific
(prompt, context builder, response validator, fallback formatter) is a parallel,
independent set of classes — not a second, unrelated Gemini integration and not a
forced reuse of Comparison's classes either.

```text
Gate::authorize('view', $analysis)          # same AnalysisPolicy as GET /analyses/{analysis}
Analysis::isPendingQuizCompletion()          # same Phase 3B.3 Result Lock, reused as-is
analysis.status === SUCCEEDED                # else 409 EXPLANATION_NOT_AVAILABLE
role = auth user's role (student|regular)    # never accepted from the client
check ai_explanations cache (analysis_id, task_type, language, role, prompt_version, schema_version)
  hit  -> return cached content immediately, Gemini never called
  miss -> GeminiClient::generate(...) -> ResultExplanationResponseValidator
            valid   -> persist to ai_explanations -> return AVAILABLE
            invalid/any failure -> ResultExplanationFallbackFormatter -> return FALLBACK (never persisted)
```

A versioned cache table (`ai_explanations`, keyed by `analysis_id` + `task_type` +
`language` + `role` + `prompt_version` + `schema_version`, entirely separate from the
deterministic `analyses`/`analysis_conclusions`/`rule_traces` tables) means the same
explanation is never regenerated on every screen open — a cache hit returns instantly
with no Gemini call. `task_type` keeps this table safely reusable by any future AI
presentation feature without mixing data; only `RESULT_EXPLANATION` exists today.
A fallback explanation is **never** persisted as though it were successful Gemini
output, so the next request always gets a fresh chance at a real Gemini response
rather than being stuck showing a stale fallback.

The same historical Report Details endpoint (Phase 4B, `GET /reports/{report}`)
already exposes `analysis.id`, so the frontend requests an explanation for a
historical analysis exactly the same way it does for a live one — no separate
historical-explanation endpoint exists, and the cache is keyed to the specific
`analysis_id`, so a historical explanation is never generated for the wrong verified
version.

Full architecture, exact Gemini payload/schema/validator/fallback, cache design,
privacy contract, and live-verification results:
[docs/phase-4e-result-explanation.md](docs/phase-4e-result-explanation.md).

## Report History

`GET /reports` (Phase 4A) lists the authenticated user's own reports for the mobile
Dashboard "Recent Reports" preview and the full Report History screen. It is a
read-only query over the existing `reports` table — there is no separate history
table, and the endpoint never triggers OCR, KBS analysis, or quiz preparation.

```text
Report::query()->where('user_id', $request->user()->getKey())
    ->when($testCategory, ...)->when($status, ...)
    ->orderByDesc('created_at')
    ->paginate($perPage)
```

- **Ownership**: scoped directly by `auth()->user()->getKey()` — there is no
  request-supplied `user_id`, so a user can never enumerate another user's reports.
  `ReportPolicy` has no `viewAny` (list scoping is inherent to the query), only the
  pre-existing `view`/`update` single-report checks used elsewhere.
- **Sorting**: `created_at` descending (newest first) — fixed, not configurable.
- **Pagination**: `per_page` (default 10, max 50) and `page` query parameters, backed by
  Laravel's `LengthAwarePaginator`.
- **Filters**: `test_category` (`CBC`/`DIABETES`/`LIVER_FUNCTION`) and `status` (any
  `ReportStatus` value), both validated against the real enums — an unknown value is a
  422 `VALIDATION_ERROR`, never silently ignored.
- **Response fields** (`ReportHistoryResource`): `id`, `test_category`, `source_type`,
  `status`, `report_date`, `created_at`, `updated_at`. Deliberately excludes OCR rows,
  verified values, KBS conclusions/rule traces, and report files — those remain scoped
  to their existing single-report endpoints and to the future Phase 4B report-details
  endpoint.
- **Performance**: the existing `[user_id, created_at]` and `[user_id, status]`
  composite indexes on `reports` (added in Phase 2) already cover this query shape, so
  no new migration or index was needed. The endpoint does not eager-load `analyses` or
  `quizSessions` — an "is a result available" / "quiz summary" field was considered and
  intentionally omitted because it cannot be computed per row without either an N+1
  query or replicating `Analysis::isPendingQuizCompletion()`'s quiz-lock logic outside
  its owning service; the plain `status` field is returned instead.

Response shape:

```json
{
  "success": true,
  "data": {
    "reports": [
      { "id": 57, "test_category": "CBC", "source_type": "IMAGE", "status": "COMPLETED", "report_date": null, "created_at": "2026-08-15T10:00:00.000000Z", "updated_at": "2026-08-15T10:05:00.000000Z" }
    ],
    "pagination": { "current_page": 1, "per_page": 10, "total": 4, "last_page": 1, "has_more": false }
  }
}
```

## Report Details

`GET /reports/{report}` (Phase 4B) returns one report's full historical record: its
current verified values and the stored KBS result that belongs to that exact
verified-result-set version, if one exists. It is **read-only** — it never reruns
OCR or KBS, never creates or mutates a `VerifiedResultSet`/`Analysis`/`QuizSession`,
and never dispatches a queue job. `ShowReportController` is the only place this data
is composed; `AnalysisResource` and `ReportPolicy` are reused as-is from the live
analysis/verification endpoints rather than duplicated.

```text
Gate::authorize('view', $report)                         # same ReportPolicy as every other report endpoint
$verifiedResultSet = $report->verifiedResultSets()->latest('version')->first()
$analysis = SelectHistoricalAnalysis::forVerifiedResultSet($verifiedResultSet)   # see below
$quizSummary = QuizSession::latestCompletedForReport($report->id, $user->id)
```

- **Ownership**: `Gate::authorize('view', $report)` — the same `ReportPolicy::view()`
  check (`$report->user_id === $user->getKey()`) used by every other report-scoped
  endpoint. A non-owner gets **403 `FORBIDDEN`**, matching the existing project
  convention (`ExtractedResultController`, `ShowReportVerificationController`,
  `ShowAnalysisController`) rather than a 404 — this endpoint does not attempt a
  different anti-enumeration behavior than the rest of the API already has. A
  nonexistent or soft-deleted report id 404s via normal route-model binding.
- **Version consistency (critical)**: the "current" `VerifiedResultSet` is the highest
  `version` for the report (`latest('version')->first()`, mirroring
  `ShowReportVerificationController`'s existing idiom). The `analysis` shown is always
  scoped to *that exact* verified-result-set id — never picked independently — so an
  older analysis can never be paired with a newer verification version.
- **Which Analysis is "the" historical result** (`app/Services/Reports/SelectHistoricalAnalysis.php`):
  a verified result set can have more than one `Analysis` row (`flow` is part of
  `Analysis.identity_key`, so a quiz-first attempt and a direct-result attempt never
  collide — see [Quiz Backend](#quiz-backend)). Selection priority, mirroring the same
  "quiz-first is never a reachable Final Result until its quiz is completed" principle
  `Analysis::isPendingQuizCompletion()` already enforces:
  1. Most recently completed `SUCCEEDED` **direct-result** analysis.
  2. Otherwise, the most recently completed `SUCCEEDED` **quiz-first** analysis whose
     quiz has actually been completed — never a locked/pending one.
  3. Otherwise, the most recent **failed** analysis (any flow) — shown honestly rather
     than omitted.
  4. Otherwise, the most recent still-queued/processing analysis.
  5. Otherwise `null` — no analysis has ever been attempted for this version.
- **Response composition**: `report` (scalar fields, same shape as
  `ReportHistoryResource` plus nothing extra), `verification` (`id`, `version`,
  `patient_age_years`, `patient_sex`, `confirmed_at`, `values[]` via the new
  `HistoricalVerifiedResultResource` — deliberately excludes `original_confidence`,
  `source_extracted_result_id`, and `page`, which are OCR-review internals, not part
  of what was confirmed and analyzed), `analysis` (the **exact same**
  `AnalysisResource` shape as `GET /analyses/{analysis}`, reused verbatim so the
  mobile client's existing result-rendering logic works unchanged), and
  `quiz_summary` (`status`, `score`, `total` — only when a `QuizSession` for this
  report/user is `COMPLETED`; a single cheap indexed query via the existing
  `QuizSession::latestCompletedForReport()` helper, not a Quiz History feature).
- **No-side-effect guarantee**: proven by a dedicated regression test that snapshots
  row counts across `reports`, `verified_result_sets`, `verified_results`,
  `analyses`, `analysis_conclusions`, `rule_traces`, and `quiz_sessions`, calls the
  endpoint, and asserts every count is unchanged and no queue job was pushed.
- **Performance**: one query for the latest verified result set (`with('results')`),
  at most a handful of small indexed queries for analysis selection, one eager-load
  (`conclusions`, `ruleTraces`, `verifiedResultSet.results`) on the single selected
  `Analysis`, and one indexed query for the quiz summary — no N+1, no loading of
  every historical version or every quiz snapshot.

Response shape:

```json
{
  "success": true,
  "data": {
    "report": { "id": 60, "test_category": "CBC", "source_type": "IMAGE", "status": "COMPLETED", "report_date": null, "created_at": "...", "updated_at": "..." },
    "verification": { "id": 37, "version": 1, "patient_age_years": 29, "patient_sex": "FEMALE", "confirmed_at": "...", "values": [ { "id": 287, "label": "HGB", "value": "9.5", "unit": "g/dL", "reference_range": "12-16", "was_added_manually": true, "was_modified": false, "display_order": 1 } ] },
    "analysis": { "id": 28, "status": "SUCCEEDED", "flow": "direct-result", "result": { "conclusions": [ { "code": "possible_anemia_pattern", "title": { "en": "..." }, "evidence": [] } ], "rule_traces": [ { "rule_code": "...", "fired": true } ], "verified_results": [ "..." ] } },
    "quiz_summary": null
  }
}
```

A report that has not been verified yet returns `verification: null, analysis: null,
quiz_summary: null` alongside its `report.status` (e.g. `UPLOADED`/`PROCESSING`/
`NEEDS_REVIEW`); a verified report with no analysis attempt yet returns real
`verification` values with `analysis: null` — never a fabricated result.

## Comparison + AI Contextualization

`POST /comparisons` (Phase 4C) compares **2+ same-category** reports and returns a
fully deterministic, Laravel-computed comparison (`comparison`) plus an optional
Gemini-generated, role-aware explanation of those already-computed facts
(`ai_context`). The two layers are strictly separated: Laravel is the only source of
numeric truth (raw trend, reference-interval relationship, a higher-level
**lab-change classification** distinguishing "returned to the reference range" from
"moved toward it but is still abnormal", and deterministic KBS pattern transitions —
`APPEARED`/`DISAPPEARED`/`PERSISTED`/`TRANSIENT` across the compared reports); Gemini
only narrates what Laravel already decided and pre-grouped, in the requested language
and at a depth matched to the requesting user's role (`regular` vs `student`), and
never recalculates, diagnoses, or recommends treatment. AI is fully optional — with no
`GEMINI_API_KEY` configured (or on any AI failure), the endpoint still returns `200`
with a deterministic bilingual, role-aware fallback explanation in `ai_context`, and
the `comparison` object itself is entirely unaffected either way. No `comparisons`
table exists; every comparison is computed fresh from existing `Report`/
`VerifiedResultSet`/`Analysis` rows and nothing is persisted or mutated.

```text
Gate::authorize('view', $report)  per report        # same ReportPolicy as every other report endpoint
same test_category across every selected report      # else 409 COMPARISON_CATEGORY_MISMATCH, before any AI call
every report has >=1 VerifiedResultSet                # else 409 COMPARISON_REPORT_NOT_VERIFIED
order oldest -> newest by Report.created_at
per report: latest VerifiedResultSet -> SelectHistoricalAnalysis (same service as Phase 4B)
cross-report analyte matching by stable KBS analyte_id, or OCR hint fallback — never free-text label
trend = earliest vs. latest comparable point, purely-technical 0.1% float tolerance
reference_trend from KBS-sourced numeric bounds only — never parsed from free text
lab_change_classification = f(earliest/latest reference status, reference_trend) — e.g. LOW->less-LOW is
  MOVED_CLOSER_BUT_STILL_ABNORMAL, never NORMALIZED (regression-locked by dedicated tests)
pattern_transitions = earliest-vs-latest KBS conclusion_code presence, never decided by Gemini
GroupAnalyteChanges pre-sorts analytes into sections BEFORE Gemini ever sees them —
  Gemini explains section membership, it never decides it
GeminiContextualizer::contextualize(...)  -> AVAILABLE (validated) or FALLBACK (deterministic), never throws
```

Full architecture, algorithms, exact Gemini payload/schema/validator/prompt strategy,
safety controls, configuration, and live-verification results:
[docs/phase-4c-comparison.md](docs/phase-4c-comparison.md).

## API Endpoints

All routes are under `/api/v1`, rate-limited by `throttle:api` (auth endpoints have
additional dedicated limiters).

**Authentication**
| Method | URI | Auth |
|---|---|---|
| POST | `/auth/register` | — |
| POST | `/auth/login` | — |
| POST | `/auth/forgot-password` | — |
| POST | `/auth/reset-password` | — |
| POST | `/auth/logout` | Bearer |
| GET | `/auth/me` | Bearer |

**Users / Profile**
| Method | URI | Auth |
|---|---|---|
| GET | `/users/me` | Bearer |
| PATCH | `/users/me` | Bearer |
| DELETE | `/users/me` | Bearer |

**Reports / OCR / Jobs**
| Method | URI | Auth |
|---|---|---|
| GET | `/reports` | Bearer |
| POST | `/reports` | Bearer |
| GET | `/reports/{report}` | Bearer |
| POST | `/reports/{report}/files` | Bearer |
| POST | `/reports/{report}/process` | Bearer |
| GET | `/reports/{report}/extracted-results` | Bearer |
| GET | `/jobs/{job}` | Bearer |

**Verification**
| Method | URI | Auth |
|---|---|---|
| POST | `/reports/{report}/verification` | Bearer |
| GET | `/reports/{report}/verification` | Bearer |

**Analysis**
| Method | URI | Auth |
|---|---|---|
| POST | `/reports/{report}/analyze` | Bearer |
| GET | `/analyses/{analysis}` | Bearer |
| POST | `/analyses/{analysis}/explanation` | Bearer |

**Quiz**
| Method | URI | Auth |
|---|---|---|
| POST | `/reports/{report}/quiz` | Bearer, student only |
| GET | `/quiz/{quiz}` | Bearer |
| POST | `/quiz/{quiz}/answers` | Bearer |
| GET | `/students/me/quiz-history` | Bearer, student only |

**Comparison**
| Method | URI | Auth |
|---|---|---|
| POST | `/comparisons` | Bearer |

Full request/response bodies: [docs/phase-1-api.md](docs/phase-1-api.md),
[docs/phase-2-ocr.md](docs/phase-2-ocr.md), [docs/phase-3-analysis.md](docs/phase-3-analysis.md),
[docs/phase-3b-quiz.md](docs/phase-3b-quiz.md), [docs/phase-4c-comparison.md](docs/phase-4c-comparison.md),
[docs/phase-4d-quiz-history.md](docs/phase-4d-quiz-history.md), [docs/phase-4e-result-explanation.md](docs/phase-4e-result-explanation.md).
Postman collection: [docs/postman/LabLearn-Phase1.postman_collection.json](docs/postman/LabLearn-Phase1.postman_collection.json).

### Planned / Not Implemented

Nothing from the currently scoped Blueprint phases remains unimplemented as of Phase
4E; the next phase (if any) has not been scoped yet.

## Error Contract

Every JSON error response has `success: false`, a human-readable `message`, and a stable
`error_code`. Validation failures (422) additionally include a field-keyed `errors` map.

```json
{
  "success": false,
  "message": "Validation failed.",
  "error_code": "VALIDATION_ERROR",
  "errors": { "email": ["The email field is required."] }
}
```

```json
{
  "success": false,
  "message": "Unauthenticated.",
  "error_code": "UNAUTHENTICATED"
}
```

422 = validation, 401 = unauthenticated/invalid token, 403 = forbidden (including
`QUIZ_RESULT_LOCKED`, `QUIZ_STUDENT_ONLY`), 404 = missing route/resource, 409 = state
conflict (e.g. `QUIZ_SESSION_NOT_ACTIVE`, `CATEGORY_GATE_NOT_MATCHED`), 429 = throttled.
Unexpected production errors are sanitized to `INTERNAL_ERROR` — stack traces, SQL
errors, secrets, and tokens are never returned.

## Queues and Jobs

Default queue connection is `QUEUE_CONNECTION=database` (synchronous `sync` in the test
environment, per `.env.testing.example`). Jobs:

- `App\Jobs\ProcessReportOcr` — runs OCR extraction for a report file.
- `App\Jobs\ProcessReportAnalysis` — runs the KBS analysis for a report/verified-result
  set.
- `App\Jobs\FinalizeQuizPreparation` — finalizes a `PREPARING` quiz session once its
  underlying analysis completes.

Run the worker manually:

```bash
php artisan queue:work --tries=3 --timeout=240
```

## Artisan Commands

| Command | Purpose |
|---|---|
| `php artisan ocr:health` | Checks connectivity to the configured OCR service. |
| `php artisan kbs:health` | Checks connectivity to the configured KBS service and prints its metadata. |
| `php artisan quiz:refresh-general-bank [--force]` | Regenerates the KBS-driven General Question Bank (see above). |
| `php artisan kbs:repair-localized-analysis-content [--apply]` | Localization-only backfill of Arabic title/summary/evidence-label text on historical SUCCEEDED analyses from the current KBS catalog — never a medical re-analysis. Dry-run by default. See [docs/localization-integrity-repair.md](docs/localization-integrity-repair.md). |

## Startup / Shutdown

The normal full-project developer workflow is the repository-root launcher:

```text
start-lablearn.cmd   → start-lablearn.ps1
stop-lablearn.cmd    → stop-lablearn.ps1
```

`start-lablearn.ps1` checks prerequisites, writes local `.env` configuration (LAN IP,
OCR/KBS URLs, generates internal API keys if missing), installs dependencies if needed,
starts the MySQL/MariaDB Windows service if it isn't running, then — in this exact order
— clears Laravel's config cache, applies pending migrations
(`php artisan migrate --force`), and refreshes the General Question Bank
(`php artisan quiz:refresh-general-bank`, with `QUIZ_REFRESH_GENERAL_BANK_ON_START` set
to `true` for this dev-only launcher). Migrations must be applied first because the
refresh command writes to the `questions` table; the KBS knowledge base is read directly
from disk, so no KBS service needs to be running yet at this point. Once that succeeds,
it starts the OCR API, Laravel API, KBS (Streamlit) and KBS JSON API windows, waits for
each to report healthy, runs `ocr:health`/`kbs:health` as a live connectivity check, then
starts the queue worker and Expo. `stop-lablearn.cmd` stops only the process windows this
launcher started (the shared MySQL service is left running).

`start-lablearn.ps1 -CheckOnly -SkipInstall` runs the same configuration/migration/
refresh steps without starting any persistent service — useful for a fast validation
pass.

## Tests

```bash
php artisan test
vendor/bin/pint --dirty
```

KBS tests are a separate Python test suite, run from the `kbs/` directory with its own
virtual environment:

```bash
cd kbs
python -m unittest discover -s tests
```

As of this README's last update: **223 Laravel tests passing, 2176 assertions**
(including `Phase4A/` for Report History and `Phase4B/` for Report Details). Treat
this as a snapshot, not a guarantee — re-run the commands above for the current
state. Feature tests are organized by phase (`tests/Feature/Auth`, `Phase2`, `Phase3`,
`Phase3B`, `GeneralQuestionBank`, `Phase4A`, `Phase4B`, `Seeder`, `User`); unit tests
cover isolated service logic (`tests/Unit/Phase2`, `Phase3`).

## Security

- All authenticated endpoints require a valid Sanctum bearer token; ownership is
  re-checked server-side via Policies on every access, not inferred from the token
  alone.
- Internal service credentials (`OCR_SERVICE_API_KEY`, `KBS_SERVICE_API_KEY`) are read
  only inside `OcrClient`/`KbsClient`, attached as request headers, and never written to
  logs — service call logging includes only non-sensitive identifiers (request id,
  status code, analysis id).
- `.env.example` / `.env.testing.example` contain placeholders only — never commit real
  secrets, tokens, or database passwords.
- The KBS JSON API is bound to `127.0.0.1` and must never be exposed on the LAN or
  through a firewall rule; only Laravel calls it.
- Production error responses never include stack traces, raw SQL, or credentials.

## Medical Safety

LabLearn is an **educational** tool for lab-result interpretation practice, not a
diagnostic system. The backend and KBS must never present a confirmed diagnosis,
treatment plan, or medication dosage. All KBS conclusions are traceable, rule-based
classifications with an explicit disclaimer, not medical advice. Generated General
questions carry `review_status = GENERATED_PENDING_REVIEW` and must go through a real
medical review process before being treated as clinically vetted content.

## Localization Integrity

When `language=ar`, every human-readable prose field (KBS conclusion
titles/summaries, evidence/analyte labels, Phase 4C/4E AI explanations and their
deterministic fallbacks) must contain genuine Arabic — intentional Latin-script
medical abbreviations, units, rule/condition codes, and numeric values are not
exceptions to police against. A prior audit found and this repair fixed a real
bug where English prose was silently stored under an `ar` key at the KBS layer;
see [docs/localization-integrity-repair.md](docs/localization-integrity-repair.md)
for the root cause, the fix across KBS/Laravel/frontend, the new
`LanguagePurityChecker` response-validation gate, and the
`kbs:repair-localized-analysis-content` historical backfill command.

## Known Limitations / Deferred Work

- Phase 4A (Dashboard Recent Reports + Report History listing), Phase 4B (Historical
  Report Details), Phase 4C (Multi-report Comparison + AI Contextualization), Phase 4D
  (Student Quiz History + real Dashboard quiz statistics), and Phase 4E (role-aware AI
  result explanation) are all implemented.
- `GET /reports/{report}`'s `quiz_summary` remains intentionally a trivial `{status,
  score, total}` block — full question/answer review for that same quiz is available
  via `GET /students/me/quiz-history` + `GET /quiz/{quiz}` (Phase 4D), not inline on
  the report-details response.
- Quiz History's `summary.overall_percentage` has no per-category variant — see
  [docs/phase-4d-quiz-history.md](docs/phase-4d-quiz-history.md#known-limitations).
  No Weak Topics, mastery score, or adaptive-learning model exists or was added.
- `GET /reports` intentionally omits an "is a result available" / quiz-summary field per
  report — computing it correctly (respecting the same quiz-completion lock as
  `GET /analyses/{analysis}`) without an N+1 query was judged out of scope for a
  lightweight history list; the report's own `status` is returned instead.
- Two places in this backend generate AI text — Phase 4C's `POST /comparisons`
  (cross-report trend explanation) and Phase 4E's
  `POST /analyses/{analysis}/explanation` (single-result, role-aware explanation) —
  both strictly validated, always-fallback-safe, and never a source of numeric truth,
  diagnosis, or treatment recommendation. See
  [docs/phase-4c-comparison.md](docs/phase-4c-comparison.md) and
  [docs/phase-4e-result-explanation.md](docs/phase-4e-result-explanation.md). No other
  AI-generated medical reasoning exists anywhere else in the backend or KBS.
- No Guest AI result explanation exists — Guest cannot currently reach a real
  succeeded `Analysis` at all (see
  [docs/phase-4e-result-explanation.md](docs/phase-4e-result-explanation.md#guest-behavior-audited-not-invented)),
  so there was nothing to build an explanation architecture for.
- No `comparisons` table/history exists — every comparison is computed fresh per
  request and nothing is persisted, so a comparison cannot currently be re-fetched by
  id or reviewed later; this was a deliberate scope decision (see
  [docs/phase-4c-comparison.md](docs/phase-4c-comparison.md#fixed-product-decisions)),
  not an oversight.
- Phase 4C's `allowed_medical_context` reuses the exact same Phase 4E Approved
  Medical Context Catalog, scoped to only the `APPEARED`/`PERSISTED` KBS pattern
  transitions relevant to explaining a comparison's change (a `DISAPPEARED` pattern
  gets no medical-context lookup — see docs/phase-4c-comparison.md's 2026-08-17
  update).
- The 360-question General Question Bank (current snapshot) is generated content and has
  not undergone medical review; `review_status = GENERATED_PENDING_REVIEW` reflects this
  honestly.
- Case-Specific question coverage is currently 6 templates / 6 rule codes, a small
  fraction of the KBS's active rule set.
- Guest sessions have no authenticated backend flow — guests cannot call the Phase 2+
  report endpoints at all (see `frontend/README.md` for the corresponding frontend-side
  guest wall).
- The liver OCR analyte registry has documented follow-up work noted in the KBS
  repository (`kbs/docs/`) that is out of scope for this backend.
