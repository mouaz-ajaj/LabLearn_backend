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
- [General Question Bank](#general-question-bank)
- [Question Bank Refresh Command](#question-bank-refresh-command)
- [Case-Specific Questions](#case-specific-questions)
- [Result Gating](#result-gating)
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
| Phase 4 — Report history/comparison, learning progress, AI reasoning | Not started |

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
```

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
| POST | `/reports` | Bearer |
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

**Quiz**
| Method | URI | Auth |
|---|---|---|
| POST | `/reports/{report}/quiz` | Bearer, student only |
| GET | `/quiz/{quiz}` | Bearer |
| POST | `/quiz/{quiz}/answers` | Bearer |

Full request/response bodies: [docs/phase-1-api.md](docs/phase-1-api.md),
[docs/phase-2-ocr.md](docs/phase-2-ocr.md), [docs/phase-3-analysis.md](docs/phase-3-analysis.md),
[docs/phase-3b-quiz.md](docs/phase-3b-quiz.md). Postman collection:
[docs/postman/LabLearn-Phase1.postman_collection.json](docs/postman/LabLearn-Phase1.postman_collection.json).

### Planned / Not Implemented

A report-listing/history endpoint (`GET /reports`) does not exist yet — the frontend
dashboard's recent-reports list is currently static mock data pending Phase 4. Report
comparison and learning-progress endpoints are likewise not implemented.

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

As of this README's last update: **192 Laravel tests passing, 1973 assertions**
(including the `GeneralQuestionBank/` suite added for Phase 3B.4). Treat this as a
snapshot, not a guarantee — re-run the commands above for the current state. Feature
tests are organized by phase (`tests/Feature/Auth`, `Phase2`, `Phase3`, `Phase3B`,
`GeneralQuestionBank`, `Seeder`, `User`); unit tests cover isolated service logic
(`tests/Unit/Phase2`, `Phase3`).

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

## Known Limitations / Deferred Work

- Phase 4 (report history, comparison, learning-progress tracking) has not started; no
  corresponding backend endpoints exist.
- AI-generated medical reasoning/contextualization is out of scope and not implemented
  anywhere in the backend or KBS.
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
