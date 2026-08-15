<?php

namespace App\Services\Verification;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\ExtractedResult;
use App\Models\Report;
use App\Models\User;
use App\Models\VerifiedResultSet;
use Illuminate\Support\Facades\DB;

class VerifyReport
{
    public function __construct(private readonly CategoryGate $categoryGate) {}

    /** @param array<string, mixed> $payload */
    public function handle(Report $report, User $user, array $payload): VerifiedResultSet
    {
        return DB::transaction(function () use ($report, $user, $payload): VerifiedResultSet {
            $lockedReport = Report::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            $existing = $lockedReport->verifiedResultSets()
                ->where('idempotency_key', $payload['idempotency_key'])
                ->with('results')
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            if (! in_array($lockedReport->status, [ReportStatus::NeedsReview, ReportStatus::Verified, ReportStatus::Analyzed, ReportStatus::Completed], true)) {
                throw new ApiException('REPORT_NOT_REVIEWABLE', 'Only a reviewable report can be verified.', 409);
            }

            $sourceIds = collect($payload['rows'])->pluck('source_extracted_result_id')->filter()->values();
            $excludedIds = collect($payload['excluded_source_result_ids'] ?? [])->values();
            if ($sourceIds->intersect($excludedIds)->isNotEmpty()) {
                throw new ApiException('VERIFICATION_SOURCE_INVALID', 'Included and excluded sources cannot overlap.', 422);
            }

            $allSourceIds = $sourceIds->merge($excludedIds)->unique()->values();
            $sourceRows = ExtractedResult::query()
                ->where('report_id', $lockedReport->getKey())
                ->whereIn('id', $allSourceIds)
                ->get()
                ->keyBy('id');
            if ($sourceRows->count() !== $allSourceIds->count()) {
                throw new ApiException('VERIFICATION_SOURCE_INVALID', 'A source row does not belong to this report.', 422);
            }

            $gate = $this->categoryGate->evaluate(
                $lockedReport->test_category,
                collect($payload['rows'])->pluck('label')->all(),
            );
            $set = $lockedReport->verifiedResultSets()->create([
                'version' => ((int) $lockedReport->verifiedResultSets()->max('version')) + 1,
                'confirmed_by_user_id' => $user->getKey(),
                'patient_age_years' => $payload['patient_age_years'],
                'patient_sex' => $payload['patient_sex'],
                'idempotency_key' => $payload['idempotency_key'],
                'excluded_source_result_ids' => $excludedIds->all(),
                'category_gate_status' => $gate['status'],
                'category_gate_category' => $gate['category'],
                'category_gate_evidence' => $gate['evidence'],
                'confirmed_at' => now(),
            ]);

            foreach ($payload['rows'] as $index => $row) {
                $source = isset($row['source_extracted_result_id'])
                    ? $sourceRows->get($row['source_extracted_result_id'])
                    : null;
                $verifiedLabel = trim($row['label']);
                $set->results()->create([
                    'source_extracted_result_id' => $source?->getKey(),
                    'label' => $verifiedLabel,
                    'value' => trim($row['value']),
                    'unit' => $this->nullableTrim($row['unit'] ?? null),
                    'reference_range' => $this->nullableTrim($row['reference_range'] ?? null),
                    'canonical_analyte_id_hint' => $this->canonicalAnalyteHintFor($source, $verifiedLabel),
                    'page' => $source?->page,
                    'original_confidence' => $source?->confidence,
                    'was_added_manually' => $source === null,
                    'was_modified' => $source !== null && $this->wasModified($source, $row),
                    'display_order' => $index + 1,
                ]);
            }

            $lockedReport->update([
                'patient_age_years' => $payload['patient_age_years'],
                'patient_sex' => $payload['patient_sex'],
                'status' => ReportStatus::Verified,
            ]);

            return $set->load('results');
        }, 3);
    }

    /** @return list<string> */
    public function nextActions(VerifiedResultSet $set, User $user): array
    {
        if ($set->category_gate_status !== 'MATCH') {
            return [];
        }

        return $user->role === UserRole::Student
            ? ['VIEW_ANALYSIS_RESULT', 'TAKE_QUIZ_FIRST']
            : ['VIEW_ANALYSIS_RESULT'];
    }

    /**
     * Carry OCR's resolved analyte-identity candidate forward only when the verified
     * label is still, character-for-character (case/whitespace aside), the label OCR
     * actually resolved it from. This is a purely syntactic check -- Laravel has no
     * medical/unit knowledge and needs none: if the user edits the label at all, the
     * candidate that was resolved from the *original* label text is no longer
     * trustworthy and must not be silently carried forward.
     *
     * This does NOT check whether the unit was also edited: a user may legitimately
     * change "Lymphocytes" from "%" to "K/uL" without touching the label, in which
     * case the (now stale) percent-based hint is still sent, but KBS -- which is
     * where unit/percent-vs-absolute knowledge belongs -- independently re-derives
     * the correct identity from the verified label+unit and never blindly trusts a
     * hint that conflicts with what the verified data itself indicates.
     */
    private function canonicalAnalyteHintFor(?ExtractedResult $source, string $verifiedLabel): ?string
    {
        if ($source === null || $source->ocr_kbs_test_id === null) {
            return null;
        }

        $unchanged = mb_strtolower(trim((string) $source->raw_label)) === mb_strtolower($verifiedLabel);

        return $unchanged ? $source->ocr_kbs_test_id : null;
    }

    /** @param array<string, mixed> $row */
    private function wasModified(ExtractedResult $source, array $row): bool
    {
        return trim((string) $source->raw_label) !== trim($row['label'])
            || trim((string) $source->raw_value) !== trim($row['value'])
            || trim((string) $source->raw_unit) !== trim((string) ($row['unit'] ?? ''))
            || trim((string) $source->raw_reference) !== trim((string) ($row['reference_range'] ?? ''));
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
