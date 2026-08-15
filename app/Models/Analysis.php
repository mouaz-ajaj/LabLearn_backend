<?php

namespace App\Models;

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\QuizSessionStatus;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'report_id', 'verified_result_set_id', 'verified_result_set_version', 'user_id',
    'report_category', 'status', 'flow', 'identity_key', 'schema_version',
    'input_schema_version', 'engine_version', 'ruleset_version', 'catalog_version',
    'started_at', 'completed_at', 'failed_at', 'duration_ms', 'attempt_count',
    'error_code', 'safe_error_message', 'normalized_results_json', 'facts_json',
    'missing_information_json', 'warnings_json', 'disclaimer_json', 'summary_json',
    'raw_kbs_response_json',
])]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory;

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function verifiedResultSet(): BelongsTo
    {
        return $this->belongsTo(VerifiedResultSet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conclusions(): HasMany
    {
        return $this->hasMany(AnalysisConclusion::class)->orderBy('display_order');
    }

    public function ruleTraces(): HasMany
    {
        return $this->hasMany(RuleTrace::class)->orderBy('id');
    }

    /**
     * Result Locking (Phase 3B.3): a quiz-first Analysis exists only to feed
     * Case-Specific question generation and is never itself the "Final Result" a
     * Student is entitled to until they actually complete the quiz that depends on
     * it. Direct-result analyses (Regular users, and Students who chose direct-result
     * or used the "view result without quiz" escape hatch) are never locked by this
     * check — Phase 3A behavior for them is unchanged.
     */
    public function isPendingQuizCompletion(): bool
    {
        if ($this->flow !== AnalysisFlow::QuizFirst) {
            return false;
        }

        return ! QuizSession::query()
            ->where('analysis_id', $this->getKey())
            ->where('status', QuizSessionStatus::Completed)
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'flow' => AnalysisFlow::class,
            'schema_version' => 'integer',
            'input_schema_version' => 'integer',
            'verified_result_set_version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'duration_ms' => 'integer',
            'attempt_count' => 'integer',
            'normalized_results_json' => 'array',
            'facts_json' => 'array',
            'missing_information_json' => 'array',
            'warnings_json' => 'array',
            'disclaimer_json' => 'array',
            'summary_json' => 'array',
            'raw_kbs_response_json' => 'array',
        ];
    }
}
