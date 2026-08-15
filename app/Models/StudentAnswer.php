<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quiz_session_id', 'quiz_question_snapshot_id', 'selected_option_id', 'is_correct', 'answered_at'])]
class StudentAnswer extends Model
{
    public function quizSession(): BelongsTo
    {
        return $this->belongsTo(QuizSession::class);
    }

    public function questionSnapshot(): BelongsTo
    {
        return $this->belongsTo(QuizQuestionSnapshot::class, 'quiz_question_snapshot_id');
    }

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }
}
