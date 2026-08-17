<?php

namespace App\Models;

use App\Enums\AiTaskType;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'analysis_id', 'task_type', 'language', 'role', 'schema_version', 'prompt_version',
    'model', 'content_json', 'status',
])]
class AiExplanation extends Model
{
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    protected function casts(): array
    {
        return [
            'task_type' => AiTaskType::class,
            'role' => UserRole::class,
            'content_json' => 'array',
        ];
    }
}
