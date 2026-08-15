# Phase 3A — Laravel to KBS Analysis Integration

This document is the operational contract for the Phase 3A analysis slice: running the deterministic KBS engine against an exact Verified Result Set version and persisting a structured, traceable result. It does not include quiz generation/scoring, report comparison, learning progress, or AI-generated medical reasoning — those remain out of scope for this phase.

## Architecture

```text
Authenticated mobile client (direct-result flow only)
-> Laravel /api/v1/reports/{report}/analyze
-> category-gate + ownership + idempotency checks (synchronous)
-> database-backed ProcessReportAnalysis queue job
-> KBS JSON API on 127.0.0.1:8601 (internal only)
-> structured conclusions/evidence/rule-traces in MySQL
-> mobile client polls Laravel only, via GET /api/v1/analyses/{analysis}
```

The mobile client must never call the KBS API directly. It has no knowledge of `127.0.0.1:8601`, the `X-Internal-KBS-Key` header, or the KBS request/response schema — those live entirely in `app/Services/Kbs/`.

## KBS JSON API contract

Service: `kbs/api/app.py` (FastAPI), independent of the Streamlit UI (`kbs/app.py`) — both call the same `core/` engine.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/health` | none | liveness + engine/ruleset/category summary |
| `GET` | `/v1/metadata` | `X-Internal-KBS-Key` | full version + schema metadata |
| `POST` | `/v1/analyze` | `X-Internal-KBS-Key` | run the deterministic analysis |

`POST /v1/analyze` request (schema_version `1`):

```json
{
  "request_id": "uuid",
  "schema_version": "1",
  "report_category": "CBC",
  "verified_result_set": { "id": 3, "version": 1 },
  "patient_context": { "age": 24, "sex": "female", "fasting": null },
  "results": [
    { "source_id": 16, "label": "HGB", "value": "10.2", "unit": "g/dL", "reference": "12-16" }
  ]
}
```

Response (abridged — see `kbs/api/schemas.py` for the full Pydantic contract):

```json
{
  "success": true,
  "schema_version": "1",
  "engine_version": "1.0.0",
  "ruleset_version": "2026.07.24.2",
  "category": "CBC",
  "category_validation": { "status": "MATCH", "matched_analytes": ["hemoglobin"], "missing_required_evidence": [] },
  "normalized_results": [ { "analyte_id": "hemoglobin", "value": 10.2, "unit": "g/dL", "status": "low" } ],
  "facts": [ { "analyte_id": "hemoglobin", "status": "low" } ],
  "conclusions": [
    {
      "code": "possible_anemia_pattern", "level": "pattern",
      "title": { "en": "Possible anemia pattern", "ar": null },
      "summary": { "en": "Low hemoglobin, hematocrit, or RBC may suggest an anemia pattern.", "ar": "..." },
      "evidence": [ { "source_id": 16, "analyte_id": "hemoglobin", "label": "Hemoglobin", "value": 10.2, "unit": "g/dL", "status": "low" } ],
      "rule_codes": ["R001"]
    }
  ],
  "rule_traces": [
    { "rule_code": "R001", "rule_version": "1", "fired": true, "conditions": [ { "group": "any", "analyte_id": "hemoglobin", "result": true } ], "evidence": [ "..." ], "conclusion_codes": ["possible_anemia_pattern"] }
  ],
  "missing_information": [ { "code": "MISSING_OPTIONAL_ANALYTE", "analyte_id": "mcv", "message": { "en": "...", "ar": "..." } } ],
  "warnings": [ { "code": "MEDICAL_REVIEW_PENDING" } ],
  "disclaimer": { "en": "Educational decision support only...", "ar": "..." }
}
```

KBS error codes (mapped from `core/api_contract.py::ContractError`): `INVALID_INPUT_SCHEMA`, `UNSUPPORTED_CATEGORY`, `CATEGORY_AMBIGUOUS`, `CATEGORY_MISMATCH`, `NO_SUPPORTED_ANALYTES`, `UNSUPPORTED_UNIT`, `INVALID_ANALYTE_VALUE`, `MISSING_REQUIRED_CONTEXT`, `UNAUTHORIZED_INTERNAL_CLIENT`, `INTERNAL_SECURITY_NOT_CONFIGURED`, `RULE_ENGINE_ERROR`, `INTERNAL_ERROR`. No Python stack trace is ever included in a response (verified by `kbs/tests/test_api.py`).

Authentication: `INTERNAL_KBS_API_KEY` on the KBS side must match `KBS_SERVICE_API_KEY` in `backend/.env`, compared with `secrets.compare_digest` (constant-time). If unset on the KBS side, the service fails closed (503) rather than allowing unauthenticated requests, unless `ALLOW_EMPTY_INTERNAL_KBS_KEY=true` is explicitly set for local development.

**Supported categories**: `CBC`, `DIABETES`, `LIVER_FUNCTION` — exactly the three from Phase 2. The analyte catalog and category→panel mapping is defined once, in `kbs/knowledge_base/{tests,liver_tests,panels}.json`, and is the single authoritative source read by both the KBS engine and Laravel's `CategoryGate` (`base_path('../kbs/knowledge_base/...')`) — there is no second, independently-maintained catalog in Laravel.

## Laravel analysis API

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/reports/{report}/analyze` | start (or return the existing) analysis for a verified result set |
| `GET` | `/api/v1/analyses/{analysis}` | poll status / fetch the persisted structured result |

`POST .../analyze` request:

```json
{ "verified_result_set_id": 3, "flow": "direct-result" }
```

`flow` is `direct-result` or `quiz-first`. Only `direct-result` runs the KBS pipeline in Phase 3A; `quiz-first` returns `409 ANALYSIS_NOT_PROCESSABLE` without ever calling the KBS (Phase 3B remains a stored handoff, not implemented here).

**`POST .../analyze` is asynchronous / queue-backed, not synchronous.** The request performs its validation (auth, ownership, category gate, idempotency lookup) and a KBS `/v1/metadata` handshake synchronously, then dispatches `ProcessReportAnalysis` onto the database queue and returns immediately — `202 Accepted` with `status: "QUEUED"` on first creation, or `200 OK` with the current status if an equivalent analysis already exists. The actual KBS `/v1/analyze` call and result persistence happen later, inside the queued job, once a worker (`php artisan queue:work`) picks it up. The client is expected to poll `GET /api/v1/analyses/{analysis}` until `status` reaches `SUCCEEDED` or `FAILED` — this GET is a pure read with no side effects (see "Report lifecycle" below).

Response envelope (`AnalysisResource`) mirrors the KBS contract plus Laravel-owned lifecycle fields: `id`, `status` (`QUEUED`/`PROCESSING`/`SUCCEEDED`/`FAILED`), `versions` (`schema`, `input_schema`, `engine`, `ruleset`, `analyte_catalog`), `timing`, `error`, and — once `SUCCEEDED` — `result` (summary, conclusions, normalized_results, facts, missing_information, warnings, rule_traces, verified_results, disclaimer).

### Validation and authorization, in order

1. Sanctum authentication (`401 UNAUTHENTICATED`).
2. Report ownership via `ReportPolicy` (`403 FORBIDDEN`).
3. `verified_result_set_id` belongs to the report (`422`/`409 VERIFIED_RESULT_SET_INVALID`).
4. Report status is `VERIFIED` or later (`409 ANALYSIS_NOT_PROCESSABLE`).
5. `verified_result_sets.category_gate_status === 'MATCH'` (`409 CATEGORY_GATE_NOT_MATCHED`) — `AMBIGUOUS`/`MISMATCH` are blocked here, before any KBS call.
6. Verified rows are non-empty (`409 VERIFIED_RESULTS_EMPTY`).
7. `flow` is `direct-result` (student `quiz-first` is `409 ANALYSIS_NOT_PROCESSABLE`).

### Idempotency

No client-supplied idempotency key is required. Laravel derives a server-side `identity_key = sha256(report_id | verified_result_set_id | verified_result_set_version | flow | ruleset_version)`, unique on `analyses.identity_key`. A repeat request with the same identity:

- returns the existing analysis unchanged if it is `QUEUED`, `PROCESSING`, or `SUCCEEDED` (`200`, no new job dispatched);
- resets and re-dispatches if the prior attempt is `FAILED` (deleting its stale conclusions/rule-traces first), so retries are safe.

A new Verified Result Set version (or an explicit ruleset-version bump) produces a new `identity_key` and therefore a new, independent `Analysis` row — prior analyses are never mutated.

### Analysis lifecycle

```text
VERIFIED --(category gate MATCH + POST /analyze, returns 202 QUEUED immediately)--> Analysis QUEUED
  --(ProcessReportAnalysis job picks it up off the queue)--> Analysis PROCESSING
  --(KBS success, persisted transactionally in the same job)--> Analysis SUCCEEDED, Report COMPLETED
  --(client polls GET /analyses/{id})--> read-only; no report/analysis state changes as a result of the GET
```

The report transitions straight to `COMPLETED` inside the job's success transaction — there is no separate "delivery" write triggered by the client fetching the result. (An earlier revision of this flow set the report to the intermediate `ANALYZED` status in the job and only flipped it to `COMPLETED` as a side effect of the first `GET /analyses/{id}` call; that violated GET's read-only contract and was corrected on 2026-08-07 — see the Phase 3A completion report.)

On KBS failure (timeout, unavailable, rejected input, invalid response): `Analysis FAILED` with a safe `error_code`/`safe_error_message`, verified rows and report status (`VERIFIED`) are left untouched, and the same request can be retried once the underlying condition is fixed.

### Error codes (`error_code` in the JSON envelope)

`VALIDATION_ERROR`, `UNAUTHENTICATED`, `FORBIDDEN`, `REPORT_NOT_VERIFIED` / `ANALYSIS_NOT_PROCESSABLE`, `VERIFIED_RESULT_SET_INVALID`, `CATEGORY_GATE_NOT_MATCHED`, `VERIFIED_RESULTS_EMPTY`, `ANALYSIS_NOT_FOUND`, `KBS_SERVICE_UNAVAILABLE`, `KBS_TIMEOUT`, `KBS_AUTHENTICATION_FAILED`, `KBS_ANALYSIS_REJECTED`, `KBS_INVALID_RESPONSE`, `INTERNAL_ERROR`.

## Configuration

Backend `.env` (`config/kbs.php`):

```env
KBS_SERVICE_BASE_URL=http://127.0.0.1:8601
KBS_SERVICE_ANALYZE_ENDPOINT=/v1/analyze
KBS_SERVICE_METADATA_ENDPOINT=/v1/metadata
KBS_SERVICE_HEALTH_ENDPOINT=/health
KBS_SERVICE_TIMEOUT_SECONDS=60
KBS_SERVICE_CONNECT_TIMEOUT_SECONDS=5
KBS_SERVICE_API_KEY=
KBS_SERVICE_VERIFY_TLS=false
KBS_SERVICE_RETRY_ATTEMPTS=2
```

`php artisan kbs:health` checks connectivity end to end and prints only safe metadata (engine/ruleset versions, supported categories) — never the configured key.

## Verified live run (2026-08-07)

A real Laravel dev server, queue worker, and KBS FastAPI process (not `Http::fake`) were run together and exercised through the real HTTP layer for all three categories, plus the mismatch, KBS-unavailable, duplicate-tap, and version-independence cases. See the Phase 3A completion report for the full transcript and results.
