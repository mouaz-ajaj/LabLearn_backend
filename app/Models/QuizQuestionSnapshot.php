<?php

namespace App\Models;

use App\Enums\QuizQuestionCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'quiz_session_id', 'sequence', 'question_category', 'question_text_json', 'options_json',
    'option_order_json', 'correct_option_id', 'explanation_json', 'source_question_id',
    'source_question_version', 'case_specific_template_id', 'case_specific_template_version',
    'evidence_json', 'rule_code', 'analyte_refs_json',
])]
class QuizQuestionSnapshot extends Model
{
    public function quizSession(): BelongsTo
    {
        return $this->belongsTo(QuizSession::class);
    }

    public function sourceQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'source_question_id');
    }

    public function studentAnswer(): HasOne
    {
        return $this->hasOne(StudentAnswer::class);
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'question_category' => QuizQuestionCategory::class,
            'question_text_json' => 'array',
            'options_json' => 'array',
            'option_order_json' => 'array',
            'explanation_json' => 'array',
            'source_question_version' => 'integer',
            'case_specific_template_version' => 'integer',
            'evidence_json' => 'array',
            'analyte_refs_json' => 'array',
        ];
    }
}
