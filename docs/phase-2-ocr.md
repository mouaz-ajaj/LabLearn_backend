# Phase 2 — Laravel to FastAPI OCR Integration

This document is the operational contract for the authenticated Phase 2 backend slice. It does not include medical analysis, verified values, quizzes, comparison, or learning progress.

## Architecture

```text
Authenticated mobile client
-> Laravel /api/v1
-> private Laravel storage
-> database-backed ProcessReportOcr queue job
-> FastAPI OCR on 127.0.0.1:9001
-> raw OCR JSON + extracted_results in MySQL
-> mobile client polls Laravel only
```

The mobile client must never call FastAPI directly. The React Native API base remains:

```env
EXPO_PUBLIC_API_BASE_URL=http://192.168.50.154:8888/api/v1
```

## Actual FastAPI contract

Supported application entry point:

```text
api.app:app
```

Service endpoints:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/health` | Liveness; returns status, service, version, and configured device |
| GET | `/api/v1/health/ready` | Dependency readiness without initializing the OCR model |
| GET | `/api/v1/info` | Supported types and resource limits |
| POST | `/api/v1/ocr/analyze` | Synchronous OCR extraction |
| GET | `/docs` | Swagger UI |
| GET | `/openapi.json` | OpenAPI JSON |

`POST /api/v1/ocr/analyze` uses multipart form data:

| Field | Required | Laravel value |
| --- | --- | --- |
| `file` | yes | private report stream |
| `preprocessing_step` | no | `enhanced` by default |
| `continue_on_page_error` | no | `true` |
| `include_diagnostics` | no | `false` |
| `response_profile` | no | `full` |

There is no public language field and no report-category field in the FastAPI multipart contract. FastAPI uses automatic Arabic/English handling. Laravel stores the selected `test_category` on the report and does not send it to FastAPI; OCR remains generic and extracts the rows present in the document. The generated FastAPI OpenAPI schema confirms that `file` is the only required multipart field.

Supported extensions are `.png`, `.jpg`, `.jpeg`, and `.pdf`. Supported service MIME types are `image/png`, `image/jpeg`, `application/pdf`, and `application/octet-stream`; Laravel also validates the real MIME, extension, and file signature before private storage. Default service limits are 20 MiB per upload, 25 PDF pages, 20,000,000 decoded image pixels, 120 seconds per OCR request, and one concurrent OCR request. Multi-page PDFs are supported and the response is synchronous.

Laravel requests the `full` profile without diagnostics so field confidence is retained. Successful responses use `data.tests`. Confidence is an object per field with source/structural/semantic/calibrated values where available. Native PDF text may have null source confidence. The public FastAPI contract currently exposes page provenance but no row bounding box; consequently `extracted_results.bbox_json` remains null unless a future compatible response supplies one. Laravel stores the complete raw response separately from mapped rows.

FastAPI errors use:

```json
{
  "success": false,
  "request_id": "ocr-...",
  "api_version": "1.0.0",
  "response_contract_version": "2.0",
  "report": null,
  "error": {
    "code": "service_busy",
    "message": "...",
    "details": {}
  }
}
```

Important service statuses include 400, 401, 413, 415, 422, 500, 503, and 504. Laravel never forwards service stack traces or raw service messages to the mobile client.

OCR model initialization is lazy and process-cached. Health, readiness, Swagger, OpenAPI generation, and native-text PDFs do not initialize PaddleOCR. Scanned images/PDF pages initialize the configured CPU/GPU model on first use.

## Laravel environment

Add these values to `backend/.env`:

```env
OCR_SERVICE_BASE_URL=http://127.0.0.1:9001
OCR_SERVICE_ANALYZE_ENDPOINT=/api/v1/ocr/analyze
OCR_SERVICE_HEALTH_ENDPOINT=/health
OCR_SERVICE_TIMEOUT_SECONDS=180
OCR_SERVICE_CONNECT_TIMEOUT_SECONDS=10
OCR_SERVICE_API_KEY=
OCR_SERVICE_VERIFY_TLS=false
OCR_SERVICE_MAX_UPLOAD_BYTES=20971520
OCR_SERVICE_RESPONSE_PROFILE=full
OCR_SERVICE_PREPROCESSING_STEP=enhanced
OCR_REPORT_STORAGE_DISK=local
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=300
```

Production should use TLS verification and a protected/private service network. Do not commit secrets.

For optional shared-key protection, configure the same secret in both processes:

```env
# backend/.env
OCR_SERVICE_API_KEY=<strong-random-secret>

# OCR process environment
INTERNAL_OCR_API_KEY=<same-strong-random-secret>
```

When `INTERNAL_OCR_API_KEY` is empty, FastAPI accepts OCR calls without the header for local development. When configured, `POST /api/v1/ocr/analyze` requires `X-Internal-OCR-Key`. The liveness endpoint remains public and exposes no secret.

## Laravel APIs

All endpoints require a Sanctum bearer token.

### Create a supported report

```http
POST /api/v1/reports
Authorization: Bearer <token>
Content-Type: application/json
```

Accepted `test_category` values are exactly:

```text
CBC
DIABETES
LIVER_FUNCTION
```

Accepted `source_type` values remain `PDF`, `IMAGE`, and `CAMERA`.

CBC image example:

```json
{
  "test_category": "CBC",
  "source_type": "IMAGE"
}
```

Diabetes image example:

```json
{
  "test_category": "DIABETES",
  "source_type": "IMAGE"
}
```

Liver-function PDF example:

```json
{
  "test_category": "LIVER_FUNCTION",
  "source_type": "PDF"
}
```

Category matching is case-sensitive after Laravel's standard request trimming. Lowercase values and aliases such as `GLUCOSE`, `HBA1C`, `LIVER`, and `DIABETIC` are rejected with `VALIDATION_ERROR`; surrounding whitespace is removed by the existing middleware before enum validation.

All three categories use the same generic OCR endpoint and queue job. `DIABETES` and `LIVER_FUNCTION` only expand upload and raw extraction support: extracted labels such as glucose, HbA1c, ALT, AST, bilirubin, albumin, or any other rows present are stored under the existing raw-response contract. A report is not rejected because an expected analyte is absent. No diagnosis, medical interpretation, normalization, reference-range decision, or verified result is produced in this phase.
### Upload a private file

```http
POST /api/v1/reports/{report}/files
Authorization: Bearer <token>
Content-Type: multipart/form-data

file=@C:\path\to\report.png
```

A new upload replaces the prior file only while the report remains `UPLOADED`. Storage paths are never returned.

### Queue OCR

```http
POST /api/v1/reports/{report}/process
Authorization: Bearer <token>
```

A database transaction locks the report, verifies ownership/state/file presence, blocks an existing `QUEUED` or `PROCESSING` extraction, creates one `extraction_jobs` row, and dispatches `ProcessReportOcr`.

### Poll status

```http
GET /api/v1/jobs/{job}
Authorization: Bearer <token>
```

### Fetch extracted rows

```http
GET /api/v1/reports/{report}/extracted-results
Authorization: Bearer <token>
```

This returns raw OCR fields, page, confidence, bbox when supplied, and the raw public OCR row. It never returns the private storage disk/path.

## Lifecycle

```text
Report: UPLOADED -> QUEUED -> PROCESSING -> NEEDS_REVIEW
                                      \-> FAILED

Job:    QUEUED -> PROCESSING -> SUCCEEDED
                            \-> FAILED
```

The job uses three attempts, backoff delays of 5/30/90 seconds, and a 240-second worker timeout. Database queue `retry_after` defaults to 300 seconds to prevent overlapping execution. Connection failures, service 5xx/503/429, and timeouts are retryable. Invalid files, service 4xx validation failures, and malformed success responses are not retried.

## Stable Laravel error codes

- `REPORT_FILE_REQUIRED`
- `REPORT_FILE_INVALID`
- `REPORT_FILE_TOO_LARGE`
- `REPORT_NOT_PROCESSABLE`
- `OCR_JOB_ALREADY_RUNNING`
- `OCR_SERVICE_UNAVAILABLE`
- `OCR_TIMEOUT`
- `OCR_INVALID_RESPONSE`
- `OCR_PROCESSING_FAILED`
- `UNAUTHENTICATED`
- `FORBIDDEN`
- `VALIDATION_ERROR`
- `NOT_FOUND`

## Exact local commands

OCR terminal (GPU shown; use `OCR_DEVICE=cpu` when required):

```powershell
cd C:\Users\M3aZ\Documents\Project\project2\OCR
$env:OCR_DEVICE='gpu:0'
$env:INTERNAL_OCR_API_KEY=''
C:\Users\M3aZ\.conda\envs\ocr311\python.exe -m uvicorn api.app:app --host 127.0.0.1 --port 9001
```

Laravel terminal:

```powershell
cd C:\Users\M3aZ\Documents\Project\project2\backend
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8888
```

Queue worker terminal:

```powershell
cd C:\Users\M3aZ\Documents\Project\project2\backend
php artisan queue:work --tries=3 --timeout=240
```

Connectivity test:

```powershell
cd C:\Users\M3aZ\Documents\Project\project2\backend
php artisan ocr:health
```

Regular automated tests fake FastAPI and do not require it to be online. `ocr:health` is the real local connectivity verification.

## Current limitations

- Authenticated registered users only; guest sessions are not part of this slice.
- Upload and raw OCR extraction support exactly `CBC`, `DIABETES`, and `LIVER_FUNCTION`; medical analysis support is not part of this phase.
- No frontend report integration yet.
- No verified values, medical rules, diagnosis, AI explanation, quiz, history/comparison, or learning progress.
- Bounding boxes are not exposed by the current public FastAPI response.
- A queue worker must run separately.

## Verified review persistence

Phase 2 now continues from immutable `extracted_results` to an explicit versioned confirmation boundary. `POST /api/v1/reports/{report}/verification` atomically validates and stores patient age/reference sex, included edited rows, manual rows, and excluded source IDs. It changes the report from `NEEDS_REVIEW` to `VERIFIED`. A distinct later confirmation creates the next version; the original OCR rows and earlier verified versions are never updated.

`GET /api/v1/reports/{report}/verification` returns the latest snapshot. Pass `?version=N` for history. Both routes require Sanctum authentication and report ownership.

The request must contain an 8–64 character `idempotency_key`, required patient context, at least one included row with a non-empty label/value, and source IDs belonging to the report. Repeating the same key returns the existing set and prevents duplicate versions.

The category gate is fail-closed and reads aliases from the actual KBS JSON catalogs. Its result is `MATCH`, `AMBIGUOUS`, or `MISMATCH`. Only `MATCH` exposes role-aware handoff choices. This does not invoke KBS: the repository currently has an importable Python analyzer and Streamlit UI but no Laravel-facing JSON API. Phase 3 remains unstarted.

Updated lifecycle:

```text
Report: UPLOADED -> QUEUED -> PROCESSING -> NEEDS_REVIEW -> VERIFIED
                                      \-> FAILED
```

Additional stable error codes: `REPORT_NOT_REVIEWABLE`, `VERIFICATION_SOURCE_INVALID`, and `VERIFIED_RESULTS_NOT_FOUND`.
