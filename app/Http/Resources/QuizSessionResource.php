<?php

namespace App\Http\Resources;

use App\Enums\QuizSessionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuizSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'report_id' => $this->report_id,
            'verified_result_set_id' => $this->verified_result_set_id,
            'verified_result_set_version' => $this->verified_result_set_version,
            'report_category' => $this->report_category,
            'status' => $this->status->value,
            'target' => [
                'total' => $this->target_total,
                'general' => $this->target_general_count,
                'case_specific' => $this->target_case_specific_count,
            ],
            'actual' => [
                'total' => $this->actual_total,
                'general' => $this->actual_general_count,
                'case_specific' => $this->actual_case_specific_count,
            ],
            'progress' => [
                'answered_count' => $this->whenLoaded(
                    'questionSnapshots',
                    fn () => $this->questionSnapshots->filter(fn ($snapshot) => $snapshot->studentAnswer !== null)->count(),
                ),
            ],
            'score' => $this->score,
            'error' => $this->status === QuizSessionStatus::Failed ? ['code' => $this->prepare_error_code] : null,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Safe-by-construction: correct_option_id/explanation only ever appear
            // inside `answered`, which stays null until a StudentAnswer exists for
            // that question - the correct answer can never leak before submission.
            'questions' => $this->whenLoaded('questionSnapshots', fn () => $this->questionSnapshots
                ->map(fn ($snapshot) => $this->questionToArray($snapshot))
                ->values()
                ->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function questionToArray(mixed $snapshot): array
    {
        $answer = $snapshot->studentAnswer;

        return [
            'id' => $snapshot->getKey(),
            'sequence' => $snapshot->sequence,
            'category' => $snapshot->question_category->value,
            'question' => $snapshot->question_text_json,
            'options' => collect($snapshot->option_order_json)->map(fn ($optionId) => [
                'id' => $optionId,
                'text' => $snapshot->options_json[$optionId] ?? null,
            ])->values()->all(),
            'evidence_label' => $this->evidenceLabelFrom($snapshot->evidence_json),
            'answered' => $answer === null ? null : [
                'selected_option_id' => $answer->selected_option_id,
                'correct' => $answer->is_correct,
                'correct_option_id' => $snapshot->correct_option_id,
                'explanation' => $snapshot->explanation_json,
                'answered_at' => $answer->answered_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The client's evidence_label is a single display string (e.g. "Hemoglobin 9.5
     * g/dL"), not the raw evidence array — this renders the primary evidence entry a
     * Case-Specific question is grounded in without leaking the full structured
     * evidence payload (source_id, status, etc.) to the mobile client.
     *
     * @param  array<int, array{label?: string, value?: mixed, unit?: ?string}>|null  $evidence
     */
    private function evidenceLabelFrom(?array $evidence): ?string
    {
        $primary = $evidence[0] ?? null;
        if ($primary === null) {
            return null;
        }
        $parts = array_filter([
            $primary['label'] ?? null,
            array_key_exists('value', $primary) ? (string) $primary['value'] : null,
            $primary['unit'] ?? null,
        ], static fn ($part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' ', $parts);
    }
}
