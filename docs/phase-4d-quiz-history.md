# Phase 4D — Student Quiz History + Real Dashboard Quiz Statistics

This document is the operational contract for Phase 4D: a Student-only, paginated,
filterable history of completed quizzes, full historical question/answer review, and
real server-derived Dashboard statistics that replace the previous hard-coded
placeholder. It does not include Phase 4E (role-aware AI result explanation), Weak
Topics, Adaptive Learning, or any AI-generated content — this phase is entirely
deterministic, reading only from already-persisted quiz data.

## The old 86% — root cause

Before this phase, the Student Dashboard (`app/(student)/dashboard.tsx`) rendered two
independent, unrelated placeholders, both hard-coded and both entirely disconnected
from any real quiz result:

1. An "Overview" stat tile: `<Stat icon="ribbon-outline" value="86%" label={t('dashboard.averageQuiz')} .../>` —
   the literal string `"86%"` was written directly in the component's JSX, not sourced
   from i18n or any API response at all.
2. A static "Latest quiz performance" card: `<Text style={styles.quizScore}>{t('dashboard.quizScore')}</Text>`,
   where `dashboard.quizScore` was itself an i18n string whose *value* was the literal
   text `'86%'` (in both `en.ts` and `ar.ts`) — a translation key masquerading as a
   fixed number, not a real translated value. It sat next to a static note
   (`dashboard.quizNote`: "Student quizzes are optional before viewing results.")
   that never reflected whether the student had completed any quiz at all.

Neither value depended on `quiz_sessions.score`, `actual_total`, or any API call —
they were simply typed into the source. Both are now fully removed: the JSX no longer
contains `"86%"` anywhere, and the `averageQuiz`/`quizScore`/`recentQuiz`/`quizNote`
i18n keys were deleted from both `en.ts` and `ar.ts` rather than left as dead code. A
regression test (`frontend/tests/quiz-history.test.ts`, "the old hard-coded 86 percent
placeholder no longer appears anywhere...") asserts the literal string `86%` is absent
from the Dashboard source and from both translation files, and that the four retired
keys no longer exist.

They are replaced by real Dashboard statistics and a real "Recent Quizzes" section,
both sourced from `GET /api/v1/students/me/quiz-history` — see below.

## History source of truth

Quiz History and its statistics read **exclusively** from the already-persisted Phase
3B tables:

```text
quiz_sessions              - one row per quiz attempt (status, score, actual_total, completed_at, ...)
quiz_question_snapshots    - immutable copy of exactly what was shown, one row per question per session
student_answers            - one immutable answer per snapshot
```

No new table was created. Historical questions are never reconstructed from the
current `questions` table, never regenerated, and never re-run through KBS — the
snapshot a `QuizQuestionSnapshot` row already carries (`question_text_json`,
`options_json`, `option_order_json`, `correct_option_id`, `explanation_json`,
`evidence_json`, `rule_code`, ...) is the sole source of truth for what a completed
quiz looked like, exactly as Phase 3B already designed it.

## Detail API — reused, not duplicated

`GET /api/v1/quiz/{quiz}` (`ShowQuizController` + `QuizSessionResource`, unchanged from
Phase 3B) already returns everything a historical review screen needs: every question
snapshot in its original `option_order_json` order, and — once a `StudentAnswer` row
exists for that snapshot — the student's `selected_option_id`, the true
`correct_option_id`, whether it was `correct`, the `explanation`, and `answered_at`,
all nested inside `answered` (which is `null` until answered, by construction). For a
`COMPLETED` session every snapshot has an answer, so this endpoint already fully
satisfies GENERAL + CASE_SPECIFIC review, correct-answer visibility, and explanations
with **zero backend changes**. `QuizSessionPolicy::view()` (pure ownership,
`quiz_sessions.user_id === $user->id`) already applies regardless of session status, so
a Student can never review another Student's quiz, completed or not. No new detail
endpoint was created — extending the existing one was unnecessary because it was
already sufficient, and the audit step of this phase explicitly confirmed that before
any code was written.

## `GET /api/v1/students/me/quiz-history` — list + statistics

The one new endpoint this phase adds. Student-only (`403 QUIZ_STUDENT_ONLY`, the same
error code and convention `StartQuizSession` already uses for quiz creation — no new
authorization convention was invented). `user_id` always comes from the authenticated
Sanctum token; it is never accepted as a request parameter.

```text
GET /api/v1/students/me/quiz-history?page=1&per_page=10&test_category=CBC
-> BuildQuizHistoryOverview::handle($user, $category, $perPage, $page)
   -> role check: 403 QUIZ_STUDENT_ONLY if not a Student
   -> summary: one aggregate query over ALL of this student's COMPLETED sessions
      (COUNT + SUM(score) + SUM(actual_total)), independent of $category
   -> items: a second, separately paginated/filtered query over the same COMPLETED
      sessions, ordered completed_at DESC, id DESC
-> { success: true, data: { summary, items: [...], pagination: {...} } }
```

### Why one endpoint, not two

Design A (summary + items + pagination in a single response) was chosen over a
separate `/quiz-stats` endpoint after auditing `ListReportsController` (Phase 4A)'s
existing single-endpoint-with-pagination convention: nothing about the aggregate stat
is expensive enough on its own to justify a second round trip, and the Dashboard needs
both the count/percentage *and* a few recent items in the same screen paint — one
request gets both. `BuildQuizHistoryOverview` performs exactly two SQL queries
(one `COUNT`/`SUM` aggregate, one paginated `SELECT`) regardless of how many quizzes or
questions exist — no `StudentAnswer` or `QuizQuestionSnapshot` row is ever loaded into
PHP for this endpoint, since everything the list/statistics need (`score`,
`actual_total`, `actual_general_count`, `actual_case_specific_count`, `completed_at`)
already lives directly on `quiz_sessions`.

### Completed-only

Only `quiz_sessions.status = 'COMPLETED'` rows are ever included in either the
`summary` or `items` — `PREPARING`/`READY`/`IN_PROGRESS`/`FAILED` sessions have no
final score and are excluded entirely, not shown with a placeholder score. This is a
`WHERE status = 'COMPLETED'` clause on both queries, not a post-filter. Nothing about
Phase 3B's existing resume flow (`GET /quiz/{quiz}` for a `READY`/`IN_PROGRESS`
session) changes — Quiz History is a separate, additive read path, not a
redefinition of what "in-progress" means.

### `summary` is always global, independent of the `category` filter

`summary.{completed_quizzes, correct_answers, total_questions, overall_percentage}`
reflects **all** of the student's completed quizzes, regardless of any
`test_category` passed in the request. Only `items` (and `pagination`) are narrowed by
the filter. This was a deliberate design decision: the Dashboard has no category filter
at all and needs the student's true overall performance, and a Quiz History screen
filtered to one category should not make its own headline statistic silently mean
something narrower than "overall" without the user asking for that. Verified directly
by a backend test ("category filter narrows the item list but the summary stays the
students true overall performance").

### Score semantics — audited, not assumed

`quiz_sessions.score` (`unsignedSmallInteger`, nullable until completion) is set in
exactly one place, `SubmitQuizAnswer::handle()`, at the moment `answered_count >=
actual_total`:

```php
$updates['score'] = StudentAnswer::query()
    ->where('quiz_session_id', $locked->getKey())
    ->where('is_correct', true)
    ->count();
```

It is a **count of correct answers**, not a percentage and not a points value. This
was confirmed by reading the exact assignment (not assumed) before any statistics code
was written.

### Overall Dashboard formula — weighted, not averaged

```text
overall_percentage = round( SUM(score) / SUM(actual_total) * 100, 1 )
```

computed via one SQL aggregate over every `COMPLETED` session
(`COALESCE(SUM(score), 0)`, `COALESCE(SUM(actual_total), 0)`), **never** as
`average(percentage_1, percentage_2, ...)`. This matters because quiz size is dynamic
(14–20 questions depending on how many Case-Specific questions were available) — a
15-question quiz and a 20-question quiz do not deserve equal weight in the overall
figure. Worked example, verified by both an automated test and a live run against the
real API:

```text
Quiz A: 12/15   Quiz B: 16/20   Quiz C: 10/16
correct = 12+16+10 = 38, total = 15+20+16 = 51
overall_percentage = round(38/51*100, 1) = 74.5   -- NOT average(80, 80, 62.5) = 74.16...
```

`overall_percentage` is `null` (never `0`) when `total_questions` is `0` (i.e. zero
completed quizzes) — a null value is how the frontend distinguishes "no data yet" from
a genuine 0% performance, so it never fabricates a number that was never earned.

### Percentage rounding convention

Two different, intentionally different conventions coexist, both documented so they
never appear contradictory in the same screen:

- **The weighted overall figure** (`summary.overall_percentage`, and every individual
  `items[].percentage`) is computed server-side and rounded to **one decimal place**
  (`round(x, 1)`) — chosen to preserve the meaningful precision the task's own
  74.5%-style example calls for, since with a fixed 1-decimal figure a 38/51 quiz and
  a (hypothetical) 39/52 quiz stay visibly distinguishable.
- **Individual quiz cards and the quiz-detail hero** display a **whole-number**
  percentage on the frontend (`Math.round((score/total)*100)`), matching the
  pre-existing convention `quiz-complete.tsx` already established for the live
  post-quiz score screen. The frontend computes this itself from the raw `score`/
  `total` integers rather than re-rounding the backend's 1-decimal value, so it always
  matches exactly what `quiz-complete.tsx` would have shown for that same quiz.
- The Dashboard's **aggregate** "Overall Score" stat tile is the one place the raw
  1-decimal backend value is shown as-is (falling back to an integer display only when
  it happens to be a whole number), since it is the only statistic in this feature
  meant to carry that extra digit of precision.

## API resources

`QuizHistoryItemResource` (list item) and the unmodified `QuizSessionResource`
(detail) are the only two resources involved — neither returns an Eloquent model
directly. `QuizHistoryItemResource` excludes `user_id`, `identity_key`,
`verified_result_set_id`, `analysis_id`, and every question/answer field; it returns
only `id`, `report_id`, `test_category`, `status`, `completed_at`, `started_at`,
`score`, `total`, `percentage`, `general_count`, `case_specific_count`.

## Configuration / migrations

**No migration was added.** The audit confirmed `quiz_sessions` already carries every
field the history list and statistics need (`score`, `actual_total`,
`actual_general_count`, `actual_case_specific_count`, `report_category`,
`completed_at`, `status`) and the existing `(user_id, status)` index already makes the
Student-scoped, completed-only queries this endpoint issues efficient — adding a new
`quiz_history` table, as explicitly warned against, would have duplicated data that
already exists and introduced a second, divergence-prone source of truth. No new
environment variable was introduced.

## Testing

- `tests/Feature/Phase4D/QuizHistoryApiTest.php` (18 tests) — unauthenticated
  rejection, Regular rejection (`403 QUIZ_STUDENT_ONLY`), zero-completed-quiz honest
  empty summary (`overall_percentage: null`, not `0`), `PREPARING`/`READY`/
  `IN_PROGRESS`/`FAILED` sessions excluded from both summary and items, the exact
  weighted-formula worked example above (38/51 = 74.5%, proven distinct from the
  naive average), newest-completed-first ordering, pagination (`per_page`,
  `has_more`), category filtering (narrows items, summary stays global), cross-student
  isolation (a student only ever sees their own history), full item field contract,
  no question/answer/internal-id leakage in the list response, and a no-side-effect
  regression (row counts unchanged before/after a history request).
- `tests/Feature/Phase4D/QuizHistoryReviewTest.php` (6 tests) — a completed review
  exposes both GENERAL and CASE_SPECIFIC questions with student answer/correct
  answer/correctness/explanation; a regression proving an unfinished session still
  never exposes `correct_option_id`/`explanation` for an unanswered question (the
  exact anti-leak guarantee Phase 3B already established, reasserted here specifically
  because Phase 4D reuses this endpoint for history); cross-student review rejection;
  editing/deactivating the source `Question` after completion does not alter the
  historical snapshot; a full General Question Bank refresh (deactivate everything +
  reseed) does not alter an already-completed quiz's snapshot ids or `actual_total`;
  reviewing a completed quiz performs no database mutation.

## Live verification (2026-08-16)

Ran against the real running stack (`php artisan serve` on `:8888`, real MySQL, real
KBS API on `:8601`, a real queue worker) with a live Student account and three real,
fully completed quizzes built by starting each quiz-first flow through the actual
`POST /reports/{report}/quiz` → real KBS pipeline, then answering every question
through the real, authenticated `POST /quiz/{quiz}/answers` endpoint (never via direct
database writes) with a deliberately varied, pre-chosen correct/incorrect pattern per
quiz:

- **CBC** (16 questions, 14 general + 2 real Case-Specific `R001`/`R002` questions
  grounded in a real HGB 9.5 g/dL + MCV 72 fL finding): 15/16 correct.
- **DIABETES** (15 questions, 14 general + 1 real Case-Specific question grounded in a
  real HbA1c 6.7% finding): 7/15 correct.
- **LIVER_FUNCTION** (15 questions, 14 general + 1 real Case-Specific question): 10/15
  correct.

`GET /students/me/quiz-history` then returned `completed_quizzes: 3`,
`correct_answers: 32`, `total_questions: 46`, `overall_percentage: 69.6` — matching
`round(32/46*100, 1) = 69.6` exactly, and visibly different from the naive average of
the three individual percentages (93.8 + 46.7 + 66.7) / 3 ≈ 69.1, live proof the
weighted formula (not an average) is what actually runs. Items were correctly ordered
newest-`completed_at`-first, each carrying its own real `score`/`total`/`percentage`/
composition. A `test_category=CBC` filter correctly narrowed `items` to one row while
`summary.completed_quizzes` remained `3` (the global figure). A Regular account
received `403 QUIZ_STUDENT_ONLY`; a second Student account received an honest empty
history (`completed_quizzes: 0`, `overall_percentage: null`, not another student's
data) and `403 FORBIDDEN` when attempting to open the first student's quiz by id.

**Live question review**: `GET /quiz/{cbc-quiz}` was fetched over real HTTP and
inspected directly — both real Case-Specific questions were present with their real
`evidence_label` ("Hemoglobin 9.5 g/dL"), real bilingual explanations referencing the
real fired rule codes (R001/R002), the real submitted `selected_option_id`, the real
`correct_option_id`, and a `correct` flag matching exactly what was intended when
answering (the one deliberately-wrong question showed `correct: false`).

**Snapshot immutability, live**: after completing the CBC quiz, its source `Question`
row was mutated directly in the database (different text, flipped
`correct_option_id`, bumped `content_version`) and the entire CBC question bank was
deactivated. Re-fetching the same quiz through the live `GET /quiz/{quiz}` endpoint
immediately afterward showed the snapshot's **original** question text and **original**
`correct_option_id`, unchanged. The bank was then cleanly restored via
`php artisan quiz:refresh-general-bank --force`, which itself reported
`Quiz sessions modified: 0`, `Question snapshots modified: 0`,
`Student answers modified: 0` — independent, command-level confirmation that
regenerating the bank never touches historical quiz data.

**Discovery during live verification (unrelated to Phase 4D code, but worth
recording)**: the real KBS engine's CBC and LIVER_FUNCTION panels now enforce
additional required analytes beyond what a minimal Phase 3B-era test fixture supplied
(CBC also requires WBC and Platelets; LIVER_FUNCTION also requires ALP, Total
Bilirubin, and Albumin) — a real `/v1/validate` preflight rejection, not a bug in this
phase's code. This only affects manually-constructed live test fixtures (real,
OCR-verified reports already carry a complete panel); no application code was changed
because of it.

All seeded live-verification data (three users, five reports and their verified
result sets/analyses/quiz sessions/snapshots/answers, all Sanctum tokens) was deleted
immediately after verification.

## Frontend

### Screens

`app/(student)/quiz-history.tsx` (list) and `app/(student)/quiz-history/[id].tsx`
(detail, a dynamic route sitting alongside the static list route, the same pattern
`report-details/[id].tsx` already established for Phase 4B) — both guarded by
`canAccessArea('quizHistory', ...)`, a new area wired to a new, Student-only
`capabilities.canAccessQuizHistory` (`true` only for the `student` role; `false` for
`regular`, `guest`, and signed-out). The list screen shows a summary header
(`completed_quizzes`, `overall_percentage`), a category filter row (reusing
`REPORT_CATEGORY_TRANSLATION_KEYS`, no new category concept), and a paginated,
pull-to-refreshable list of `QuizHistoryCard`s. The detail screen renders every
question as a scrollable list of `QuizReviewQuestionCard`s (never forcing the
one-question-at-a-time flow the *live* quiz uses) — each card reuses the exact same
correct/incorrect visual language (`theme.success`/`theme.danger`) the existing
`quiz-explanation.tsx` screen already established, and renders `question.answered`
exactly as the server supplied it, with no client-side recomputation of correctness
anywhere.

### Stores

`quizHistoryStore.ts` (paginated list + filters + a small "recent" preview for the
Dashboard, mirroring `reportHistoryStore.ts`'s dual-purpose shape) and
`quizDetailStore.ts` (fetch-by-id with a generation guard, mirroring
`reportDetailsStore.ts`) — both reused `quizApi`, extended with one new method
(`quizApi.history()`) for the list/stats endpoint and reusing the pre-existing
`quizApi.get()` for detail. Both stores are wired into `authStore.ts`'s
`clearSession()`/`continueAsGuest()` reset calls, so Student A's quiz history/
statistics can never appear after Student B signs in on the same device.

### Dashboard integration

The Dashboard now shows two real stat tiles for a Student
(`dashboard.completedQuizzes`, `dashboard.overallScore`, sourced from
`quizHistoryStore`'s `summary`) alongside the existing Reports count, and a real
"Recent Quizzes" section (mirroring the existing "Recent reports" section's loading/
error/empty/list states exactly) instead of the old single fake card. Both the overall
score and the Recent Quizzes list refetch every time the Dashboard regains focus
(`useFocusEffect`, the same pattern `recentReports` already used) — completing a new
quiz and returning to the Dashboard shows updated statistics immediately, with no app
restart. Zero completed quizzes renders `—` for the score (never `0%`) and an honest
"No completed quizzes yet" empty state, while a `0` completed-quiz **count** is shown
plainly once the fetch resolves (a count of zero is an honest answer; a percentage of
zero is not, since it implies a graded attempt that never happened).

### Regular / Guest

Regular users see none of the above — no Quiz History, no quiz statistics tiles, no
Recent Quizzes section, and no new nav item, gated identically by
`capabilities.canAccessQuizHistory` throughout. Guests never reach an authenticated
area at all (`(student)/_layout.tsx`'s existing `dashboard` guard), so no persistent
quiz data or statistics are ever requested or shown for a guest session.

## Known limitations

- No Weak Topics, mastery score, adaptive-learning score, or per-category
  proficiency model exists or was added — the only aggregate statistic is objective,
  weighted historical performance across all completed quizzes.
- `summary` in the list/stats endpoint intentionally ignores the `test_category`
  filter (see above) — there is no way to request a category-scoped overall
  percentage from this endpoint today; if a future phase needs that, it is an additive
  query parameter, not a redesign.
- Phase 4E (role-aware AI explanation) is not implemented; nothing in this phase calls
  Gemini or any AI provider.
