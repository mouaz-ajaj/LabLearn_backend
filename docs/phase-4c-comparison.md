# Phase 4C — Multi-Report Comparison + Gemini AI Contextualization

This document is the operational contract for Phase 4C: a deterministic, same-category,
2+ report comparison core computed entirely in Laravel (4C.1), plus an optional Gemini
AI layer that explains — but never computes — those already-established facts (4C.2). It
does not include Phase 4D (Quiz History) or Phase 4E (role-aware Final Result
explanation), and it does not touch OCR/KBS medical logic.

## Two-layer architecture

```text
Authenticated Student/Regular mobile client
-> POST /api/v1/comparisons  { report_ids: [...], language: "ar"|"en" }
-> ResolveComparableReports   (auth, ownership, same-category, verified-state, ordering)
-> BuildReportComparison      (per-report snapshot + cross-report analyte trend classification)
   -- 100% deterministic; the ONLY layer that computes numbers/trends --
-> GeminiContextualizer::contextualize($comparison, $user, $language)
   -> if AI disabled/unconfigured: deterministic ComparisonFallbackFormatter
   -> else: ComparisonContextBuilder -> GeminiClient -> ComparisonResponseValidator
      -> on ANY failure (timeout/5xx/invalid JSON/schema violation/unsupported id): fallback
      -> on success: validated Gemini content, status AVAILABLE
-> response: { comparison: <deterministic facts>, ai_context: { status, language, content } }
```

`comparison` is always present and always correct on its own — the endpoint is fully
usable with Gemini disabled entirely. `ai_context` is always an *additional* field; it
never overwrites or merges into `comparison`, and its `content` shape is identical
whether `status` is `AVAILABLE` or `FALLBACK` (the frontend never branches on status to
decide what to render).

## Fixed product decisions

- Only same-category reports may be compared (`CBC`+`CBC`, `DIABETES`+`DIABETES`,
  `LIVER_FUNCTION`+`LIVER_FUNCTION`). Cross-category requests are rejected by
  `ResolveComparableReports` **before** any Gemini call is attempted.
- 2–10 reports per comparison (`CreateComparisonRequest`: `min:2`, `max:10`, `distinct`).
  Nothing in the domain is hard-coded around exactly two reports.
- Laravel computes every numerical fact (increased/decreased/stable, reference-interval
  relationship, availability/missingness). Gemini never calculates these from raw
  numbers — it only narrates already-classified `trend`/`reference_trend` values.
- No `comparisons` database table exists. A comparison is computed fresh on every
  request from existing `Report`/`VerifiedResultSet`/`Analysis` rows; nothing is
  persisted, mutated, or cached. This was a deliberate choice, not an oversight — the
  Blueprint listed persistence as optional, and no requirement here needs a comparison
  to be re-fetched by ID, re-shown after a restart, or referenced from anywhere else. If
  a future phase needs comparison history, add persistence then, scoped to that need.

## `POST /api/v1/comparisons`

Request:

```json
{ "report_ids": [74, 73], "language": "en" }
```

- Sanctum authentication required (`401 UNAUTHENTICATED`).
- `report_ids`: array, 2–10 integers, `distinct` (duplicates rejected by validation,
  `422 VALIDATION_ERROR`).
- `language`: required, exactly `"ar"` or `"en"` (`422 VALIDATION_ERROR` otherwise) —
  this must be the mobile app's **current UI language** at request time, never inferred
  from server locale or Accept-Language.
- Order of `report_ids` in the request does not matter; the response always orders
  reports oldest → newest by `Report.created_at` (see [Chronological ordering](#chronological-ordering-and-report-date)).

Response (`200 OK` — see [Why 200, not 201/202](#why-200-not-201202)):

```json
{
  "success": true,
  "data": {
    "comparison": {
      "category": "CBC",
      "generated_at": "2026-08-15T23:36:29.421762Z",
      "reports": [
        { "id": 73, "sequence": 1, "date": "2026-07-06T23:35:35.000000Z", "status": "COMPLETED",
          "verified_result_set_version": 1, "analysis_id": 39, "analysis_status": "SUCCEEDED" },
        { "id": 74, "sequence": 2, "date": "2026-08-13T23:35:35.000000Z", "status": "COMPLETED",
          "verified_result_set_version": 1, "analysis_id": 40, "analysis_status": "SUCCEEDED" }
      ],
      "analytes": [
        {
          "analyte_id": "hemoglobin", "display_name": "Hemoglobin", "unit": "g/dL", "comparable": true,
          "points": [
            { "report_id": 73, "sequence": 1, "value": 9.2, "raw_value": "9.2", "unit": "g/dL",
              "reference_status": "BELOW_REFERENCE", "reference_low": 12, "reference_high": 16,
              "match_basis": "KBS_ANALYTE_ID" },
            { "report_id": 74, "sequence": 2, "value": 12.8, "raw_value": "12.8", "unit": "g/dL",
              "reference_status": "WITHIN_REFERENCE", "reference_low": 12, "reference_high": 16,
              "match_basis": "KBS_ANALYTE_ID" }
          ],
          "trend": "INCREASED",
          "reference_trend": "MOVED_CLOSER_TO_REFERENCE"
        }
      ],
      "kbs_timeline": [
        { "report_id": 73, "sequence": 1, "analysis_id": 39, "analysis_status": "SUCCEEDED",
          "conclusions": [ { "code": "possible_anemia_pattern", "level": "educational_finding",
            "title": { "en": "Possible anemia pattern", "ar": "..." },
            "summary": { "en": "Low hemoglobin needs clinical context.", "ar": "..." },
            "rule_codes": ["R001"] } ],
          "missing_information": [] },
        { "report_id": 74, "sequence": 2, "analysis_id": 40, "analysis_status": "SUCCEEDED",
          "conclusions": [], "missing_information": [] }
      ]
    },
    "ai_context": {
      "status": "AVAILABLE",
      "language": "en",
      "content": {
        "schema_version": "1", "language": "en", "category": "CBC",
        "summary": "...", "analyte_insights": [ { "analyte_id": "hemoglobin", "title": "...", "explanation": "..." } ],
        "kbs_context": [ { "rule_code": "R001", "explanation": "..." } ],
        "overall_context": "...", "limitations": "..."
      }
    }
  }
}
```

### Error codes

| Code | HTTP | Source |
| --- | --- | --- |
| `VALIDATION_ERROR` | 422 | `CreateComparisonRequest` — missing/malformed `report_ids`/`language`, fewer than 2 ids, duplicate ids |
| `NOT_FOUND` | 404 | any `report_ids` entry does not exist |
| `FORBIDDEN` | 403 | any report is not owned by the caller — same convention as every other report-scoped endpoint in this project (`ShowReportController`, `ExtractedResultController`, ...); this project does not mask ownership failures as 404 |
| `COMPARISON_CATEGORY_MISMATCH` | 409 | the selected reports span more than one `test_category` — raised **before** any Gemini call |
| `COMPARISON_REPORT_NOT_VERIFIED` | 409 | a selected report has no `VerifiedResultSet` at all yet |

A single mixed-ownership request (some reports owned by the caller, one owned by
another user) fails on the **first** unauthorized report encountered, before any other
report's data is touched or returned — nothing about the other user's report is leaked
in the response.

### Why 200, not 201/202

Every other Phase 1–4B "start work" endpoint in this backend is `201`/`202` because it
creates or queues something (`POST /reports/{report}/quiz` → `201`,
`POST /reports/{report}/analyze` → `202`). `POST /comparisons` creates nothing and
defers nothing — it synchronously computes and returns a result from data that already
exists, which is the same shape of operation as `GET /reports/{report}` (a plain,
computed `200`). No prior precedent forced either choice; `200 OK` was picked to match
the closest existing convention (a computed read), not the "started work" convention.
This was a deliberate, documented judgment call, not a discovered rule.

### Synchronous, not queued

The whole request — deterministic comparison **and** the Gemini call — runs inline in
the HTTP request/response cycle. There is no polling, no `PREPARING` status, and no
queued job anywhere in this feature. This mirrors `GET /reports/{report}` (Phase 4B),
which is also a synchronous read+compute endpoint, and avoids introducing new
asynchronous client/UI complexity for a request that (worst case: Gemini's full
`timeout_seconds` + one retry) still returns well within a normal HTTP request window.
If the real Gemini latency in production turns out to make requests feel slow, the
place to reconsider this is `GeminiContextualizer` (already fully swappable — see
[Gemini service architecture](#gemini-service-architecture)) — not the deterministic
core, which is unaffected either way since Gemini can already fail/degrade without
blocking the response.

## Chronological ordering and report `date`

`Report.report_date` is always `NULL` in the current product (no write path anywhere
ever sets it). `Report.created_at` is therefore the real, always-populated field used
for both ordering (`ResolveComparableReports::handle()`'s final `sortBy('created_at')`)
and the `date` shown per report in the response. No specimen/collection date is
invented — the field genuinely represents "when this report entered the system", which
is the most accurate honest date available.

## Version selection — reusing Phase 4B, not duplicating it

For each report, `BuildReportComparison` takes that report's **latest**
`VerifiedResultSet` (`latest('version')->first()`), then calls the exact same,
unmodified `App\Services\Reports\SelectHistoricalAnalysis::forVerifiedResultSet()`
service Phase 4B's Report Details screen uses, to pick that version's representative
`Analysis` (5-tier priority: succeeded direct-result → succeeded-and-quiz-completed
quiz-first → most recent failed → most recent queued/processing → null). This
guarantees a comparison can never mix a newer Verified version's values with an older
version's Analysis conclusions — both always come from the same `VerifiedResultSet`.
`SelectHistoricalAnalysis` has no constructor dependencies and is injected once per
report; nothing about it was changed for Phase 4C.

## Analyte matching across reports

Analytes are matched by a **stable identifier**, never by free-text label. Per verified
row (`BuildReportComparison::resolveAnalyteIdentity()`):

1. **`KBS_ANALYTE_ID`** — when the report's selected `Analysis` succeeded, its
   `normalized_results_json[]` rows are matched to `VerifiedResult` rows by
   `source_id === VerifiedResult.id` (confirmed against `KbsRequestMapper::map()`,
   which sets `'source_id' => $row->getKey()`). The row's `analyte_id`,
   `reference_range.{low,high}`, `unit`, and `display_name` all come from this
   KBS-computed structure.
2. **`OCR_HINT`** — otherwise, falls back to `VerifiedResult.canonical_analyte_id_hint`
   (populated from `ExtractedResult.ocr_kbs_test_id` at OCR time — the **same id-space**
   as KBS `analyte_id`). This hint is `null` whenever a row was manually added or its
   label was edited post-OCR, so this path is a real, expected "degraded but still
   usable" case, not an error state. Reference-interval bounds are never available on
   this path (see below).
3. **Unmatched** — a row with neither a KBS-validated `analyte_id` nor an OCR hint is
   **excluded entirely** from cross-report comparison. It is never fuzzy-matched by its
   free-text `label`, because two differently-worded labels for the same analyte (or
   two identically-worded labels for genuinely different analytes) cannot be
   distinguished reliably by string matching alone.

`match_basis` (`KBS_ANALYTE_ID` / `OCR_HINT`) is surfaced on every point in the
response, so the frontend/user can see when a comparison point's identity is less
certain than another's, rather than presenting both bases as equally authoritative.

## Reference-interval data reliability

Only `Analysis.normalized_results_json[].reference_range.{low,high}` — numeric,
KBS-computed, present only when a succeeded `Analysis` exists for that exact analyte —
is treated as a trustworthy reference interval. `VerifiedResult.reference_range` is
free, unstructured OCR/manual-entry text and is **never** parsed for reference-interval
computation anywhere in this feature. An `OCR_HINT`-matched point therefore always has
`reference_status: "UNKNOWN"` and null `reference_low`/`reference_high` — this is
correct, not a bug: no numeric bound is safely available for it.

## Unit safety

A track is `comparable: true` only when every comparable point shares one consistent
unit (`CompareAnalyteSeries::buildTrack()`: `count($units) <= 1`) and there are ≥2
numeric points. A unit mismatch across reports for the same `analyte_id` never triggers
a silent conversion — the track's `trend`/`reference_trend` become `NOT_COMPARABLE` /
`UNKNOWN`, `comparable` is `false`, and every point is still returned (with its own
real unit) so the user can see the raw values rather than have the analyte disappear.

## Missing-analyte handling

An analyte present in some but not all of the compared reports is represented
explicitly, not discarded: `CompareAnalyteSeries` builds the union of every analyte id
seen across all snapshots, and each report contributes `null` for any analyte it
doesn't have. If fewer than 2 reports have a numeric value for that analyte, the track's
`trend` is `INSUFFICIENT_DATA` (not silently omitted from `analytes[]`).

## Trend classification algorithm

Trend compares **only the earliest vs. latest chronologically-comparable numeric
point** for that analyte — not a full monotonic multi-point regression. This
deliberately matches the feature's own framing ("did this go up or down between the
oldest and newest selected report"), not a general trend-line fit.

```text
< 2 comparable (numeric) points               -> INSUFFICIENT_DATA
>= 2 points but inconsistent units             -> NOT_COMPARABLE
otherwise: delta = latest.value - earliest.value
  |delta| <= tolerance                          -> STABLE
  delta > tolerance                             -> INCREASED
  delta < -tolerance                            -> DECREASED
```

`tolerance = max(|earliest|, |latest|, 1.0) * 0.001` — a **purely technical**
floating-point-noise guard (0.1% relative, with a floor of 1.0 to stay sane near zero),
**not** a clinically validated stability threshold. It exists only to absorb rounding
noise from unit-normalization arithmetic upstream; any value change larger than that
noise floor still classifies as increased/decreased. No clinical percentage-change
threshold (e.g. "a 5% change matters, less doesn't") was invented anywhere in this
feature — that kind of judgment is explicitly out of scope for deterministic code.

## Reference-interval relationship algorithm

Per point, `ReferenceIntervalComparison::status()` classifies `WITHIN_REFERENCE` /
`BELOW_REFERENCE` / `ABOVE_REFERENCE` / `UNKNOWN` (when either bound is unavailable) —
always from the KBS-sourced numeric bounds described above, never invented.

`ReferenceIntervalComparison::trend()` (earliest status/value vs. latest status/value):

```text
either status UNKNOWN                          -> UNKNOWN
both WITHIN                                    -> REMAINED_WITHIN_REFERENCE
WITHIN -> not WITHIN                           -> MOVED_FARTHER_FROM_REFERENCE
not WITHIN -> WITHIN                           -> MOVED_CLOSER_TO_REFERENCE
BELOW -> ABOVE, or ABOVE -> BELOW               -> UNKNOWN  (crossed all the way through; ambiguous)
same direction outside (both BELOW or both ABOVE):
  distance = "low - value" (BELOW) or "value - high" (ABOVE), 0 when within
  |latest.distance - earliest.distance| <= tolerance  -> REMAINED_OUTSIDE_REFERENCE
  latest.distance < earliest.distance                 -> MOVED_CLOSER_TO_REFERENCE
  latest.distance > earliest.distance                 -> MOVED_FARTHER_FROM_REFERENCE
```

Same 0.1%-relative technical tolerance as the value trend, applied to the distance
metric. A below↔above direction flip is intentionally reported as `UNKNOWN` rather than
guessed at — "moved closer/farther" has no unambiguous meaning once a value has crossed
the entire interval and come out the other side.

## KBS timeline

`kbs_timeline[]` carries, per report, that report's own selected Analysis's
conclusions (`code`, `level`, bilingual `title`/`summary`, `rule_codes`) and
`missing_information`, **only** when that Analysis succeeded — nothing is rerun,
re-derived, or merged across reports. A report with no succeeded Analysis simply
contributes an empty `conclusions`/`missing_information` array for its timeline entry;
the report itself is still fully present in `reports[]`/`analytes[]`.

## Read-only guarantee

Nothing in `ResolveComparableReports`, `BuildReportComparison`, or
`CompareAnalyteSeries` writes to the database. No OCR job is dispatched, no KBS call is
made outside of the two services already covered by `SelectHistoricalAnalysis` (which
itself performs no KBS call — it only reads already-persisted `Analysis` rows), no
`Analysis`/`VerifiedResultSet`/`QuizSession` row is created or mutated, and no
`comparisons` table exists to write to. Asserted directly by
`tests/Feature/Phase4C/ComparisonApiTest.php` (queue-empty and row-count-unchanged
assertions around a comparison request).

## Gemini AI Contextualization (Phase 4C.2)

### Service architecture

```text
App\Contracts\AiContextualizer                 (interface — swap point for a future provider)
  -> App\Services\Ai\GeminiContextualizer       (implementation, bound in AppServiceProvider)
       -> App\Services\Ai\ComparisonContextBuilder    (deterministic comparison -> minimal payload)
       -> App\Services\Ai\GeminiPromptBuilder         (system instruction + user content + response schema)
       -> App\Services\Ai\GeminiClient                (thin REST client, mirrors KbsClient exactly)
       -> App\Services\Ai\ComparisonResponseValidator (never trusts Gemini output directly)
       -> App\Services\Ai\ComparisonFallbackFormatter (deterministic bilingual fallback, same schema)
App\Services\Ai\AiContextResult                  (readonly value object: status + content)
```

Swapping to a different AI provider in the future means writing one new class that
implements `AiContextualizer` and rebinding it in `AppServiceProvider` — nothing in
`Comparison Core` (`ResolveComparableReports`/`BuildReportComparison`/
`CompareAnalyteSeries`/`ReferenceIntervalComparison`) references Gemini, Ai, or any
provider-specific concept at all.

### Exact data sent to Gemini (`ComparisonContextBuilder::build()`)

```json
{
  "task": "comparison_contextualization",
  "language": "en",
  "user_role": "regular",
  "category": "CBC",
  "reports": [
    { "sequence": 1, "date": "2026-07-06T23:35:35.000000Z",
      "kbs_conclusions": ["Low hemoglobin needs clinical context."],
      "kbs_rule_codes": ["R001"] },
    { "sequence": 2, "date": "2026-08-13T23:35:35.000000Z",
      "kbs_conclusions": [], "kbs_rule_codes": [] }
  ],
  "analyte_trends": [
    { "analyte_id": "hemoglobin", "display_name": "Hemoglobin", "unit": "g/dL",
      "values": [9.2, 12.8], "trend": "INCREASED", "reference_trend": "MOVED_CLOSER_TO_REFERENCE" }
  ],
  "allowed_medical_context": []
}
```

**Explicitly never included, anywhere in this payload or in the HTTP request to
Gemini**: user name, account email, Sanctum token, report id / verified-result-set id /
analysis id, filenames or file paths, raw OCR text, uploaded image/PDF content, or any
internal secret/config value. Only `comparable: true` analyte tracks are included (a
`NOT_COMPARABLE` unit-mismatch track is withheld from Gemini entirely — there is
nothing safe to narrate about it). `allowed_medical_context` is always `[]` today,
because no approved, medically-reviewed symptom-context catalog exists in this project
yet — it is a deliberate, documented extension point, not a stub that happens to be
empty. `user_role` (`student`/`regular`) is included only for the prompt's modest tone
adaptation described below — this is **not** Phase 4E's role-aware explanation feature.
Verified directly by `tests/Unit/Phase4C/ComparisonContextBuilderTest.php` and by a
prompt-contract test in `ComparisonAiTest.php` that greps the actual serialized HTTP
request body for the absence of the user's real name/email/token/any file path.

### Gemini REST call

`GeminiClient` is a thin, dependency-free REST client (no vendor SDK), mirroring
`KbsClient`'s mechanics exactly: base URL + timeout + bounded retry + status-code error
mapping + logging that never includes the API key or raw prompt/response content.

```text
POST {base_url}/v1beta/models/{model}:generateContent
Header: x-goog-api-key: <GEMINI_API_KEY>
Body:
{
  "contents": [{ "role": "user", "parts": [{ "text": "<user content>" }] }],
  "systemInstruction": { "parts": [{ "text": "<system instruction>" }] },
  "generationConfig": {
    "responseMimeType": "application/json",
    "responseSchema": { ... },
    "temperature": 0.2,
    "maxOutputTokens": <config>
  }
}
```

The model's answer is read from `candidates[0].content.parts[0].text` (a JSON string,
then `json_decode`d). Retry is bounded (`retry_attempts`, default 2 total attempts —
see [Timeout and retry](#timeout-and-retry-behavior)) and only applied to genuinely
retryable failures (429, 503, 5xx, connection/timeout errors) — never to a validation
failure or a 4xx content-rejection, which fail immediately.

### System prompt strategy

`GeminiPromptBuilder::systemInstruction($language)` builds (in code, not copied
verbatim from any external source) a system instruction that establishes Gemini's role
as **explanation-only**:

- Treats every `trend`, `reference_trend`, value, unit, and KBS conclusion in the
  supplied context as an immutable, authoritative fact — never recalculate, never
  alter.
- Forbids introducing any diagnosis/condition/finding absent from the supplied
  `kbs_conclusions`/`kbs_rule_codes`.
- Forbids treatment, medication, or dosage recommendations of any kind.
- Forbids claiming clinical improvement/deterioration from lab values alone.
- Permits a possible symptom implication **only** when a matching item exists in
  `allowed_medical_context` (currently always empty, so this never fires today), and
  only with probabilistic wording ("may"/"could"/"may be associated with") — never a
  claim that a symptom actually improved, disappeared, or worsened.
- Requires referencing analyte identifiers and KBS rule codes **only** from the ones
  present in the supplied input.
- Requires a single valid JSON object matching the schema, no Markdown/code fences/
  extra text.
- Requires every human-readable text field to be written in the **requested**
  language, while every JSON key stays the exact stable English key defined by the
  schema.
- Requires `limitations` to explicitly state the comparison is educational only, is
  not a diagnosis or treatment plan, and does not confirm clinical improvement or
  deterioration.

For Arabic, the instruction additionally specifies professional Modern Standard
Arabic with standard medical abbreviations (HGB, MCV, HbA1c, ALT, AST, ...) kept in
Latin letters — verified live (see below) to actually happen in real model output.

### Response schema (Gemini `responseSchema`, OpenAPI-subset)

`schema_version`, `language`, and `category` are pinned via a single-value `enum` at
request time (`responseSchema($language, $category)`), not left as free-form strings —
this was added after live testing showed the model would otherwise return semantically
equivalent but non-identical values (e.g. `"1.0"` instead of the exact literal `"1"`
the validator requires), causing every real response to be needlessly rejected. Pinning
the exact expected value via `enum` removes that ambiguity entirely rather than relying
solely on prose instructions.

```json
{
  "type": "OBJECT",
  "properties": {
    "schema_version": { "type": "STRING", "enum": ["1"] },
    "language": { "type": "STRING", "enum": ["<requested language>"] },
    "category": { "type": "STRING", "enum": ["<requested category>"] },
    "summary": { "type": "STRING" },
    "analyte_insights": { "type": "ARRAY", "items": { "type": "OBJECT",
      "properties": { "analyte_id": {"type":"STRING"}, "title": {"type":"STRING"}, "explanation": {"type":"STRING"} },
      "required": ["analyte_id", "title", "explanation"] } },
    "kbs_context": { "type": "ARRAY", "items": { "type": "OBJECT",
      "properties": { "rule_code": {"type":"STRING"}, "explanation": {"type":"STRING"} },
      "required": ["rule_code", "explanation"] } },
    "overall_context": { "type": "STRING" },
    "limitations": { "type": "STRING" }
  },
  "required": ["schema_version", "language", "category", "summary", "analyte_insights", "kbs_context", "overall_context", "limitations"]
}
```

### `ComparisonResponseValidator` — never trusts Gemini output directly

Runs on every response before it can ever reach `status: AVAILABLE`:

1. Top-level keys must be **exactly** the 8 schema keys (no extra, none missing).
2. `schema_version === '1'`, `language === <requested>`, `category === <requested>`.
3. `summary`/`overall_context`/`limitations` are bounded (≤1500 chars), non-empty
   (except `overall_context`), safe strings.
4. `analyte_insights[]`: a list, ≤30 items, each exactly `{analyte_id, title,
   explanation}`, `analyte_id` **must already be in the comparison's own comparable
   analyte set** (`ComparisonContextBuilder::allowedAnalyteIds()` — structural
   allow-listing, not numeric re-diffing), no duplicate `analyte_id`, `title`
   ≤200 chars, `explanation` ≤1000 chars, both safe strings.
5. `kbs_context[]`: same shape/size rules, `rule_code` **must already be in the
   comparison's own KBS timeline** (`allowedRuleCodes()`), no duplicates.
6. Every text field is checked against `FORBIDDEN_PATTERNS` — a narrow, explicitly
   imperfect English + Arabic regex keyword list for dosage/medication instructions
   (`mg`, `dosage`, `تناول`, `جرعة`, ...) and confirmed-cure/diagnosis claims (`cured`,
   `تم الشفاء`, `تشخيص مؤكد`, ...).

**Structural allow-listing (steps 4–5) is the primary, reliable safety mechanism** —
Gemini structurally cannot reference an analyte or rule code Laravel didn't supply, so
it cannot invent a new finding out of nothing. The keyword blocklist (step 6) is a
supplementary, deliberately narrow net for the two specific forbidden content
categories named in the product spec; it is **not** presented as a comprehensive
medical-content validator, because a regex cannot verify medical correctness — only
reject a known-bad shape. Any single violation returns `null`, and the caller always
falls back to the deterministic formatter rather than show partially-trusted content —
there is no "partial acceptance" path anywhere in this validator.

### Deterministic fallback formatter

`ComparisonFallbackFormatter::format()` produces the **identical** `ai_context.content`
schema without calling Gemini at all: a counts-based summary ("Comparing N CBC
reports: X increased, Y decreased, ..."), one bilingual sentence template per analyte
per `trend`/`reference_trend` combination, `kbs_context` built by directly reusing each
report's own already-localized `AnalysisConclusion.summary_json`/`title_json` (never
inventing new medical text), and a fixed bilingual `limitations` disclaimer. Fully
honors the **requested** language — an Arabic request never silently shows English
text, verified directly by a live fallback test (see below).

### Failure → fallback matrix

| Trigger | `ai_context.status` | HTTP response |
| --- | --- | --- |
| `AI_COMPARISON_CONTEXT_ENABLED=false` | `FALLBACK` | still `200`, comparison unaffected |
| `GEMINI_API_KEY` empty/missing | `FALLBACK` | still `200` |
| Connection error / DNS / TLS failure | `FALLBACK` | still `200` |
| Timeout | `FALLBACK` | still `200` |
| HTTP 429 / 503 / any 5xx from Gemini | `FALLBACK` (after exhausting bounded retries) | still `200` |
| HTTP 401/403 (bad key) | `FALLBACK` | still `200` |
| Response missing `candidates[0].content.parts[0].text` | `FALLBACK` | still `200` |
| `text` is not valid JSON | `FALLBACK` | still `200` |
| Valid JSON but fails `ComparisonResponseValidator` (wrong language/category/schema_version, unknown analyte id, unknown rule code, forbidden pattern, oversized field, extra/missing key) | `FALLBACK` | still `200` |

`GeminiContextualizer::contextualize()` wraps its entire Gemini call + validation in a
single `try`/`catch (Throwable)` and **never** lets an exception propagate to the
controller — every one of the rows above degrades to the same `AiContextResult`
shape. Verified by 17 tests in `tests/Feature/Phase4C/ComparisonAiTest.php`, none of
which ever call the real Gemini API (`Http::fake()`/`Http::preventStrayRequests()`
throughout).

### Timeout and retry behavior

`config/ai.php` (`GEMINI_SERVICE_TIMEOUT_SECONDS=20`,
`GEMINI_SERVICE_CONNECT_TIMEOUT_SECONDS=5`, `GEMINI_SERVICE_RETRY_ATTEMPTS=2`). Retry
is bounded (2 total attempts by default, matching this project's existing
`KBS_SERVICE_RETRY_ATTEMPTS=2` convention in `config/kbs.php`) and only re-attempts
retryable failures (429/503/5xx/connection/timeout) — a non-retryable rejection (4xx
content error, validation failure) fails on the first attempt. The default was raised
from an initial `1` to `2` after live testing against the real Gemini API surfaced
genuine transient `429 RESOURCE_EXHAUSTED`/`503 UNAVAILABLE` responses under back-to-back
requests; a single bounded retry measurably improved the real success rate without
introducing unbounded retry risk.

### Language behavior

`language` is a required, strictly validated request field (`ar`/`en` only) supplied by
the client on every request — the frontend always sends `useSettingsStore`'s **current**
app language, never a cached/stale value, and never anything inferred server-side.
Both the deterministic comparison values (units, numbers — inherently
language-independent) and the `ai_context` content honor it: switching the app language
and re-requesting produces a freshly regenerated `ai_context` in the new language, both
for real Gemini output (the `language` schema enum forces this) and for the fallback
formatter (its own bilingual templates are keyed by the same field). There is no code
path that can show a stored Arabic paragraph inside an English-language response, or
vice versa.

### Role context

`user_role` (`student`/`regular`) is included in the Gemini payload for a **modest tone
adjustment only** — the system prompt does not currently branch its rules by role, and
no student-specific or regular-user-specific medical framing exists. This is
intentionally **not** Phase 4E ("role-aware final result explanation"), which remains
unimplemented and out of scope for this phase.

### Privacy / logging

`GeminiClient` never logs the API key, the request body, or the response body — only
operational metadata (status code, model name, finish reason on success;
status/service-error-code on rejection). The API key is read once from
`config('ai.gemini.api_key')` (sourced from `GEMINI_API_KEY`, never hard-coded), never
echoed in any response, and `.env.example` carries only an empty placeholder.

## Configuration (`config/ai.php`)

```env
AI_COMPARISON_CONTEXT_ENABLED=true
GEMINI_SERVICE_BASE_URL=https://generativelanguage.googleapis.com
GEMINI_MODEL=gemini-3.7-flash
GEMINI_API_KEY=
GEMINI_SERVICE_TIMEOUT_SECONDS=20
GEMINI_SERVICE_CONNECT_TIMEOUT_SECONDS=5
GEMINI_SERVICE_RETRY_ATTEMPTS=2
GEMINI_MAX_OUTPUT_TOKENS=2048
```

`GeminiClient::isConfigured()` is `AI_COMPARISON_CONTEXT_ENABLED && !empty(GEMINI_API_KEY)`
— **safe by default**: a production deployment with no key configured serves every
comparison with the deterministic fallback automatically, with no separate "disable AI"
step required, matching the same safe-by-default pattern already established by
`config/kbs.php`/`config/ocr.php`'s `*_SERVICE_*` settings.

`GEMINI_MODEL` is fully configurable and was chosen by querying Gemini's live
`ListModels` endpoint rather than assuming a fixed model name from prior knowledge —
the originally-assumed `gemini-2.0-flash` had since been fully deprecated
("no longer available to new users") by the time of live verification.
`gemini-3.7-flash` was confirmed, via a real `generateContent` call with
`responseMimeType: application/json`, to be a current, non-preview (GA), JSON-mode
capable flash-tier model. Because the model is only ever read from `config('ai.gemini.model')`
(no other hard-coded occurrence exists anywhere in the codebase — verified by a
repository-wide grep), upgrading the default in the future is a one-line config change.

## Frontend

### Selection flow

`History → Compare Reports (visible only when `capabilities.canCompareReports` and at
least one report exists) → Select Reports (`app/(student)/compare.tsx`) → Compare`.
Guarded to `student`/`regular` sessions only — never reachable from a guest session
(`canAccessArea('comparison', ...)`, already wired to `capabilities.canCompareReports`
before this phase). The selection screen reuses `useReportHistoryStore`'s real,
paginated Phase 4A history data (no mocks) with `SelectableReportRow` in place of
`ReportHistoryCard`; selecting a report of a different category than the first
selection disables the mismatched rows (`comparisonSelectionStore`'s `lockedCategory`)
as a **UX affordance only** — the actual security boundary is
`COMPARISON_CATEGORY_MISMATCH` on the backend, not this client-side lock. The Compare
button stays disabled until ≥2 reports are selected; duplicate selection is structurally
impossible (toggling an already-selected report deselects it).

### Result flow

Mirrors the existing "start on one screen, read on the next" pattern already used by
the live-analysis flow: pressing Compare calls `comparisonResultStore.compare(ids)`
(fire-and-forget) and immediately navigates to the **static** `/compare-result` route
(no dynamic segment/id — comparisons are stateless, so there is nothing to address by
id). The result screen reads the store's live loading/data/error state, in this order:
header (category + report dates), analyte comparison cards (`AnalyteTrendCard` — trend
chip, reference-direction note, per-point value/date/reference-status rows), missing/
not-comparable states surfaced inline per analyte, KBS timeline context, then
`AiContextCard` (AI Contextual Explanation — labeled as such, never "AI Diagnosis"),
then the educational disclaimer (`limitations`). All analyte rendering consumes
Laravel's own `trend`/`reference_trend`/`reference_status` strings directly — no
client-side recomputation exists anywhere in `AnalyteTrendCard.tsx` (verified by a
frontend test asserting the component source never imports/calls the analysis or quiz
APIs).

### AI UI distinction and fallback appearance

`AiContextCard` renders `content.summary`/`analyte_insights`/`kbs_context`/
`overall_context`/`limitations` **identically** regardless of `ai_context.status` —
there is no separate "degraded" visual treatment, so a fallback response looks like a
normal, calm explanation rather than an error state. The card is always labeled with a
"Contextual Explanation" / "Comparison Explanation" concept (localized), never "AI
Diagnosis" or any wording implying a medical conclusion.

### Language / RTL-LTR / theme

The comparisons API call always reads `useSettingsStore.getState().language` live at
request time (`currentLanguage()`, matching `quiz.api.ts`'s existing pattern) — changing
the app language and re-opening/re-running a comparison always requests a freshly
regenerated `ai_context` in the new language. Layout uses the existing
`useAppDirection()` RTL/LTR helpers throughout; medical abbreviations and numeric
values stay LTR inside an RTL layout via the same convention already used elsewhere in
the app. Colors/surfaces/badges/warning states all come from the existing theme token
system (`useAppTheme()`) — no new hard-coded colors were introduced for this feature.

## Testing

- `tests/Feature/Phase4C/ComparisonApiTest.php` (20 tests) — auth, ownership,
  cross-user 403 without data leakage, category-mismatch-before-AI, unverified-report
  rejection, chronological ordering, INCREASED/DECREASED/STABLE classification,
  INSUFFICIENT_DATA for a single-point analyte, NOT_COMPARABLE for mismatched units,
  per-category (CBC/DIABETES/LIVER_FUNCTION) sanity, correct version/Analysis pairing
  across a newer unattached version, response contract shape, no OCR/KBS job
  dispatched, no database row created/mutated by a comparison request.
- `tests/Feature/Phase4C/ComparisonAiTest.php` (17 tests) — valid English/Arabic
  acceptance; rejection (→ fallback) of invalid JSON, wrong language, wrong category,
  unknown analyte id, unknown rule code, a treatment-language pattern, a cure-claim
  pattern, a missing required field, an extra field; timeout, HTTP 5xx, missing key,
  AI disabled, and schema-version mismatch all degrade to fallback; the comparison
  response always stays `200` regardless of AI outcome. The real Gemini API is never
  called in this suite (`Http::fake()` throughout, `Http::preventStrayRequests()` where
  applicable).
- `tests/Unit/Phase4C/ComparisonContextBuilderTest.php` (6 tests) — prompt-contract
  assertions that the built payload excludes name/email/token/file-path keys and
  includes `language`+`user_role`; only comparable analytes are included; KBS
  conclusions are localized correctly; `allowed_medical_context` is always `[]`;
  `allowedAnalyteIds()`/`allowedRuleCodes()` correctness.
- `frontend/tests/comparison.test.ts` (18 tests) — response-contract mapping
  (`mapComparisonResponse`), enum↔translation-key completeness, English/Arabic i18n
  structural parity, `canAccessArea('comparison', ...)` wiring, both new Zustand stores
  reset on logout/guest-transition, selection-store invariants (max count, same-category
  lock), the comparisons API always uses the shared request boundary and sends the
  live current language, the Gemini API key never appears anywhere in frontend source,
  navigation helpers only push static routes (never carry computed data through
  `params`), Dashboard/History real wiring (not a "coming soon" placeholder), and that
  the rendering components never call the analysis/quiz APIs or recompute a trend.

## Live verification (2026-08-15 / 2026-08-16)

Ran against the real running stack (`php artisan serve` on `:8888`, real MySQL, real
KBS API on `:8601`) with two seeded users and six seeded reports (two per category,
one older/one newer, values chosen to produce a real, unambiguous trend), using real
`curl` requests and real Sanctum tokens — no test doubles.

**Deterministic core** (Gemini not yet involved):
- CBC (Hemoglobin 9.2 → 12.8 g/dL): `trend: INCREASED`, `reference_trend:
  MOVED_CLOSER_TO_REFERENCE` (9.2 was `BELOW_REFERENCE`, 12.8 `WITHIN_REFERENCE`
  against a real KBS-sourced 12–16 g/dL interval), correct oldest→newest ordering,
  report 73's real `possible_anemia_pattern`/`R001` KBS conclusion present in its
  timeline entry, report 74 (no Analysis) correctly contributing an empty timeline
  entry.
- DIABETES (HbA1c 8.2% → 6.4%, matched only via `OCR_HINT` since neither report had a
  succeeded Analysis): `trend: DECREASED`, `reference_status: UNKNOWN` on both points
  (correct — no KBS-sourced bound was ever available on this match path).
- LIVER_FUNCTION (ALT 75 → 28 U/L, `OCR_HINT`): `trend: DECREASED`.
- Cross-user isolation: User B's token against User A's report ids →
  `403 FORBIDDEN`, confirming the automated test's behavior live.

**Real Gemini verification** (`GEMINI_API_KEY` already present locally; never printed,
never asked for): CBC in English and Arabic, plus DIABETES and LIVER_FUNCTION in
English — all four returned `ai_context.status: AVAILABLE` with valid JSON matching the
schema, the exact requested language, values/units identical to the deterministic
`comparison` object, only the real `hemoglobin`/`hba1c`/`alt` analyte id and the real
`R001` rule code referenced (no fabricated analyte or rule code), no diagnosis/
treatment/dosage/cure-claim language in any response, and the Arabic response kept
`HGB` in Latin letters exactly as instructed. `ComparisonResponseValidator` accepted
all four real responses on the first attempt after the `schema_version`/`language`/
`category` enum-pinning fix described above.

**Fallback-only live verification**: `AI_COMPARISON_CONTEXT_ENABLED=false` set
temporarily, server restarted, then the same CBC request repeated in both English and
Arabic. Both returned `200` with `ai_context.status: FALLBACK`, a concise deterministic
explanation correctly honoring the requested language (confirmed the Arabic response
was genuinely Arabic, not a stale/English fallback), and the full deterministic
`comparison` object (trends, reference direction, KBS timeline) fully intact and
unaffected — the comparison feature remains completely usable with AI entirely
disabled. The setting was then reverted and the server restarted back to its normal
(AI-enabled) configuration.

All seeded live-verification data (2 users, 6 reports and their verified result sets/
analyses/conclusions, both Sanctum tokens) was deleted immediately after verification.

### Local environment fix required for live Gemini testing

The live dev environment's PHP/cURL install had no configured CA bundle
(`curl.cainfo`/`openssl.cafile` both unset in `php.ini`), which made every outbound
HTTPS call to Gemini fail immediately with `cURL error 60: SSL certificate ... unable
to get local issuer certificate` — a local machine configuration gap, not an
application bug (the same `GeminiClient` code path already had a real HTTP-failure
test in `ComparisonAiTest.php`, correctly producing `FALLBACK`). Fixed locally by
downloading the official Mozilla CA bundle from `https://curl.se/ca/cacert.pem` and
pointing both ini directives at it. This is a one-time local machine setup step, not
something the application needs to do anything about at runtime.

## 2026-08-16 update: Arabic/English localization integrity repair

`ComparisonContextBuilder` now prefers the analyte's `display_name_ar` (now
propagated end-to-end from KBS through `BuildReportComparison`/
`CompareAnalyteSeries`) when `language=ar`, instead of always sending the
English name to Gemini. `GeminiPromptBuilder`'s system instruction now
explicitly tells Gemini to render an English-sourced analyte name/KBS
conclusion's *meaning* in Arabic rather than copying it verbatim.
`ComparisonResponseValidator` now rejects a `language: "ar"` response whose
prose fails the new `LanguagePurityChecker` — the same shared root cause and
fix as Phase 4E, applied identically here since Phase 4C had the exact same
mechanism. `ComparisonFallbackFormatter`'s `analyteSentence()`/`analyteInsights`
now use the Arabic display name when available instead of always injecting the
English one into an Arabic sentence. Phase 4C has no cache table (stateless by
design), so there is no prompt/schema version to bump here. Root cause and full
details: [docs/localization-integrity-repair.md](localization-integrity-repair.md).

## 2026-08-17 update: Comparison content redesign — role-aware, change-focused explanation

### Why the previous explanation was repetitive and confusing

The pre-redesign `ai_context` was a flat `analyte_insights[]` (one paragraph per
analyte trend) plus a flat `kbs_context[]` (one paragraph per rule code, deduplicated
only by `conclusion_code` across the whole timeline — never distinguishing which
report a conclusion belonged to). Nothing distinguished a value that crossed into the
reference range from one that merely moved toward it while remaining abnormal, so a
LOW → "less LOW" hemoglobin could read exactly like a LOW → WITHIN hemoglobin.
`user_role` was sent to Gemini but never used to branch the prompt or schema at all —
Regular and Student received the identical response shape. This redesign's job was to
make the comparison explain the **change** — what returned to normal, what moved in a
better direction but remains abnormal, what became new/worse, and which KBS patterns
appeared/disappeared/persisted — without ever converting a numeric trend into a claim
of clinical improvement.

### New deterministic classification: raw trend vs. reference movement vs. lab-change classification

Three genuinely different concepts, all deterministic, all computed by Laravel:

1. **`trend`** (unchanged, `ComparisonTrend`): raw numeric direction —
   `INCREASED`/`DECREASED`/`STABLE`/`INSUFFICIENT_DATA`/`NOT_COMPARABLE`.
2. **`reference_trend`** (unchanged, `ReferenceTrend`): directional relationship to the
   reference interval — `MOVED_CLOSER_TO_REFERENCE`/`MOVED_FARTHER_FROM_REFERENCE`/
   `REMAINED_WITHIN_REFERENCE`/`REMAINED_OUTSIDE_REFERENCE`/`UNKNOWN`.
3. **`lab_change_classification`** (new, `App\Enums\LabMovementClassification`): the
   higher-level product classification, computed by
   `ReferenceIntervalComparison::labMovementClassification()` purely from the
   earliest/latest `ReferenceStatus` and the already-computed `ReferenceTrend` — no new
   numeric comparison or clinical threshold was introduced:

   ```text
   BELOW/ABOVE -> WITHIN                                    -> NORMALIZED
   WITHIN -> BELOW/ABOVE                                     -> BECAME_ABNORMAL
   same side (BELOW->BELOW or ABOVE->ABOVE), MovedCloser      -> MOVED_CLOSER_BUT_STILL_ABNORMAL
   same side, MovedFarther                                    -> MOVED_FARTHER_AND_STILL_ABNORMAL
   same side, RemainedOutside                                 -> PERSISTENT_ABNORMAL_WITHOUT_MEANINGFUL_REFERENCE_MOVEMENT
   WITHIN -> WITHIN                                           -> REMAINED_WITHIN_REFERENCE
   either status Unknown, or a Below<->Above crossing          -> REFERENCE_STATUS_UNKNOWN
   < 2 comparable points / inconsistent units (unchanged)      -> INSUFFICIENT_DATA / NOT_COMPARABLE
   ```

   **The critical product distinction this locks**: HGB 8.5 → 9.5 (both `BELOW_
   REFERENCE`) is `MOVED_CLOSER_BUT_STILL_ABNORMAL`, never `NORMALIZED` — regression-
   locked by `tests/Unit/Phase4C/LabMovementClassificationTest.php`. `analytes[]` now
   additionally carries `lab_change_classification`, `earliest_status`, and
   `latest_status` per track (additive — every pre-existing field is unchanged, so this
   is fully backward compatible with anything already consuming the `comparison`
   contract).

### KBS conclusion transitions (`App\Services\Comparison\ClassifyConclusionTransitions`)

New deterministic step, added to `BuildReportComparison`'s output as
`comparison['pattern_transitions']`. For every KBS `conclusion_code` seen across the
comparison's reports that have a **succeeded** Analysis (a report with no succeeded
Analysis contributes nothing and is skipped — matching `kbs_timeline`'s own
convention), classifies presence in the earliest vs. latest succeeded report:

- **`PERSISTED`** — present in both the earliest and latest succeeded analyses.
- **`DISAPPEARED`** — present in the earliest, absent from the latest.
- **`APPEARED`** — absent from the earliest, present in the latest.
- **`TRANSIENT`** — absent from both the earliest and latest, but present in a report
  strictly between them (only possible with 3+ reports) — added so a code is never
  silently dropped rather than guessed at.

Each transition item also carries `first_seen_sequence`/`last_seen_sequence`/
`occurrence_count`/`present_in_latest` for optional multi-report richness, and the
representative `title`/`summary`/`rule_codes` from its most recent occurrence. Fewer
than 2 succeeded analyses in the comparison yields an empty list rather than a guessed
transition. Fully regression-locked (`tests/Unit/Phase4C/
ClassifyConclusionTransitionsTest.php`), including the task's exact worked example:
anemia `PERSISTED`, thrombocytosis `DISAPPEARED`, a new pattern `APPEARED`, with no
duplicate rows — and confirmed live (see below) against the real KBS engine.

### Laravel pre-groups sections — Gemini never decides membership

`App\Services\Comparison\GroupAnalyteChanges` deterministically sorts every
comparable analyte track into exactly one bucket from its `lab_change_classification`:
`normalized`, `better_but_still_abnormal`, `new_or_worse` (BECAME_ABNORMAL +
MOVED_FARTHER_AND_STILL_ABNORMAL together), `persistent_abnormal`, or counted into
`unchanged_comparable_count` (REMAINED_WITHIN_REFERENCE) — `INSUFFICIENT_DATA`/`NOT_
COMPARABLE`/`REFERENCE_STATUS_UNKNOWN` tracks appear in none of these. This single
grouping function is shared by `ComparisonContextBuilder` (what Gemini is sent, already
pre-grouped), `ComparisonResponseValidator` (the per-section allow-list Gemini's
response is checked against), and `ComparisonFallbackFormatter` (the deterministic
no-Gemini rendering) — the grouping can never drift between what Gemini was told and
what the fallback shows, and Gemini is structurally unable to move an item between
sections: an `analyte_id` supplied only inside `better_but_still_abnormal`'s allow-list
can never validate inside `normalized_findings`, regardless of what Gemini's prose
claims.

### Comparison-specific medical context (reused, not reinvented)

`App\Services\Ai\MedicalContext\ComparisonMedicalContextResolver` reuses the exact same
Phase 4E `ApprovedMedicalContextCatalog` (no second knowledge base) via a shared
`ApprovedMedicalContextCatalog::localizeGroups()` method extracted from Phase 4E's own
`ApprovedMedicalContextResolver` so both features localize catalog groups identically.
Scoped deliberately narrower than Phase 4E: only conclusion codes with transition
`APPEARED` or `PERSISTED` are resolved — a `DISAPPEARED` pattern gets **no** medical-
context lookup at all, so Gemini has nothing to draw on for explaining the medical
meaning of an absence (the product requirement here is describing the expert-system
transition itself, "no longer supported," never implying a confirmed resolution).
`allowedCodes()` exposes only `differential`/`interpretation_clues` (mapped from the
catalog's existing `differential_considerations`/`distinguishing_information` fields) —
comparison does not repeat Phase 4E's causes/symptoms/next-steps/red-flags fields,
since a comparison explains a *change*, not a finding from scratch.

### Response schema v2 (`schema_version: "2"`, role-conditional)

```json
{
  "schema_version": "2", "language": "ar", "role": "regular", "category": "CBC",
  "overall_picture": "string",
  "normalized_findings": [{ "analyte_id": "string", "text": "string" }],
  "better_but_still_abnormal": [{ "analyte_id": "string", "text": "string" }],
  "new_or_worse_findings": [{ "analyte_id": "string", "text": "string" }],
  "pattern_changes": [{ "conclusion_code": "string", "transition": "string", "text": "string" }],
  "interpretation": "string",
  "unchanged_summary": "string",
  "limitations": "string",
  "student_context": {
    "clinical_significance": "string",
    "differential_context": [{ "context_code": "string", "text": "string" }],
    "interpretation_clues": [{ "context_code": "string", "text": "string" }],
    "persistent_abnormalities": [{ "analyte_id": "string", "text": "string" }]
  }
}
```

`student_context` is present in the schema only when `role === "student"` (same
role-conditional-schema precedent as Phase 4E's `student_context`). The old
`summary`/`analyte_insights`/`kbs_context`/`overall_context` fields are gone entirely.
`role` is derived **exclusively** from `$user->role->value` inside
`GeminiContextualizer` — never accepted from Gemini's own output, and a mismatched
`role` value anywhere in the response fails strict top-level key validation (the
`student_context` key itself makes a Regular-shaped response structurally invalid for
a Student request and vice versa).

### Prompt, validator, and fallback rewrites

`GeminiPromptBuilder::systemInstruction()`/`responseSchema()` are now role-conditional
(`regularInstruction()`/`studentInstruction()`), built around explaining pre-grouped
sections rather than raw values, with explicit hard rules: never move an analyte
between sections, never call a `better_but_still_abnormal` item normalized, never
invent a `pattern_changes` entry or alter its `transition`, never claim causality
between two separate findings unless the catalog explicitly supports it, never produce
a per-analyte unchanged list, and never equate a numeric change with confirmed clinical
improvement. `ComparisonResponseValidator` checks every `analyte_id` against the
**exact section** `GroupAnalyteChanges` placed it in (not "exists anywhere in the
comparison"), and every `pattern_changes[].transition` against Laravel's own computed
value for that `conclusion_code` — a mismatch (Gemini claiming `APPEARED` for a
Laravel-computed `PERSISTED` code) fails validation exactly like an invented code
would. `ComparisonFallbackFormatter` builds the identical v2 schema deterministically
from `GroupAnalyteChanges` + `pattern_transitions` + the resolved catalog groups
(`clinical_relevance`/`patient_friendly_meaning` text feeds `interpretation`/
`student_context.clinical_significance` deterministically) — no more flat
per-analyte/per-rule-code dump.

### Frontend

`AiContextCard.tsx` rebuilt into short sections mirroring the backend's pre-grouped
shape: Regular renders "Overall Picture / Returned to Reference Range / Better
Direction, Still Abnormal / New or Concerning Changes / Changes in Detected Patterns /
What Might This Mean? / What Cannot Be Concluded" (exact Arabic/English strings in
`src/i18n/{ar,en}.ts`); Student additionally renders Persistent Abnormalities,
Clinical Significance, Differential Context, and Interpretation Clues — sections
entirely absent from Regular, not the same content relabeled. A coded-item section
with zero items renders nothing (never an empty box), and `unchanged_summary` is
rendered as one compact sentence, never a per-analyte list. `comparison.types.ts`/
`comparisonContract.ts` were extended additively (`labChangeClassification`,
`earliestStatus`, `latestStatus`, `patternTransitions`, the full v2 `AiContextContent`
shape) — `AnalyteTrendCard.tsx` (the raw deterministic trend card) was intentionally
left unchanged; the redesign is scoped to the AI explanation card.

### Live verification (2026-08-17)

Real KBS analyses + real Gemini calls (`php artisan tinker`, no mocking) across CBC
(2-report), DIABETES (2-report), LIVER_FUNCTION (2-report), and CBC (3-report,
fluctuating HGB 8.5 → 11.0 → 9.5) — 4 role×language combinations each. The CBC case
was constructed to exercise every classification and transition type at once: HGB
8.5→9.5 (`MOVED_CLOSER_BUT_STILL_ABNORMAL`), Platelets 520→350
(`NORMALIZED`, ABOVE→WITHIN), WBC 7→14 (`BECAME_ABNORMAL`, WITHIN→ABOVE), with real
KBS conclusions producing anemia-related patterns `PERSISTED`, thrombocytosis
`DISAPPEARED`, and a new infection/inflammation pattern `APPEARED` — confirmed via
`storage/logs/laravel.log` conclusion-code output before any AI call. The resulting
Regular/Arabic explanation correctly separated platelets into "returned to the
reference range" while phrasing every red-cell finding as "تحسن...ولكنه لا يزال
منخفضاً" (improved but still low) — never claiming normalization for them — correctly
labeled the new WBC finding as a new concerning change, and explained the three
pattern transitions with the required conservative "no longer supported"/newly
appeared phrasing, with zero unsupported causal claim between the WBC and red-cell
findings (kept as two separate sentences). The Student/Arabic DIABETES example
produced genuinely deeper content (CDC-referenced pathophysiology, Type 1/Type 2/
medication-induced/other-endocrine differential, concordance/clinical-context
interpretation clues) absent from the Regular response for the same comparison. The
3-report CBC case (HGB 8.5→11.0→9.5) was correctly classified from earliest (8.5)
vs. latest (9.5) only — `MOVED_CLOSER_BUT_STILL_ABNORMAL` — with no "steadily"
language, since the primary classification never inspects the middle point. Of 20
total role×language×category combinations requested, 15 returned `AVAILABLE`; the
remaining 5 fell back due to genuine `GeminiException` (confirmed via log — rate-limit-
class transient failures from rapid back-to-back calls in one script, the same known
characteristic already documented above under "Timeout and retry behavior") — **zero**
were validator rejections ("failed strict validation" does not appear in the log for
any Phase 4C call in this run).

### Documentation of this update

Per the task's explicit hard constraint: no KBS file (`rules.json`, `conditions.json`,
`rule_engine.py`, `liver_engine.py`, reference ranges, thresholds) was modified — the
KBS test suite (186 tests / 252 subtests) passes unchanged, and the new classification/
transition logic consumes only already-persisted `Analysis`/`AnalysisConclusion` rows.
Phase 4C remains stateless (no cache table); this redesign added no persistence. No
Phase 4F work was started.
