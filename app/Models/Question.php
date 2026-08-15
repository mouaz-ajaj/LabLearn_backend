<?php

namespace App\Models;

use App\Enums\QuestionReviewStatus;
use App\Enums\QuizQuestionCategory;
use App\Enums\ReportTestCategory;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'category', 'type', 'question_text_json', 'options_json', 'correct_option_id',
    'explanation_json', 'active', 'content_version', 'review_status',
    'source', 'source_type', 'source_id', 'template_family', 'generator_version', 'stable_source_key',
])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => ReportTestCategory::class,
            'type' => QuizQuestionCategory::class,
            'question_text_json' => 'array',
            'options_json' => 'array',
            'explanation_json' => 'array',
            'active' => 'boolean',
            'content_version' => 'integer',
            'review_status' => QuestionReviewStatus::class,
        ];
    }
}
