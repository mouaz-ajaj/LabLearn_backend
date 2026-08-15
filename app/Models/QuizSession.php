<?php

namespace App\Models;

use App\Enums\QuizSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'report_id', 'verified_result_set_id', 'verified_result_set_version', 'analysis_id',
    'report_category', 'status', 'identity_key', 'target_total', 'target_general_count',
    'target_case_specific_count', 'actual_total', 'actual_general_count', 'actual_case_specific_count',
    'score', 'prepare_error_code', 'started_at', 'completed_at',
])]
class QuizSession extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function verifiedResultSet(): BelongsTo
    {
        return $this->belongsTo(VerifiedResultSet::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function questionSnapshots(): HasMany
    {
        return $this->hasMany(QuizQuestionSnapshot::class)->orderBy('sequence');
    }

    public function studentAnswers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class);
    }

    /**
     * Phase 3B.3 result-gating helper: the most recently completed quiz session for this
     * report/user pair, if any. Not enforced anywhere yet in Phase 3B.2 - see
     * backend/docs/phase-3b-quiz.md "Result Gating Preparation" for how a future
     * quiz-first result-access check should use this.
     */
    public static function latestCompletedForReport(int $reportId, int $userId): ?self
    {
        return static::query()
            ->where('report_id', $reportId)
            ->where('user_id', $userId)
            ->where('status', QuizSessionStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();
    }

    protected function casts(): array
    {
        return [
            'status' => QuizSessionStatus::class,
            'verified_result_set_version' => 'integer',
            'target_total' => 'integer',
            'target_general_count' => 'integer',
            'target_case_specific_count' => 'integer',
            'actual_total' => 'integer',
            'actual_general_count' => 'integer',
            'actual_case_specific_count' => 'integer',
            'score' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
