<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['analysis_id', 'rule_code', 'rule_version', 'fired', 'conditions_json', 'evidence_json', 'conclusion_codes_json'])]
class RuleTrace extends Model
{
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    protected function casts(): array
    {
        return [
            'fired' => 'boolean',
            'conditions_json' => 'array',
            'evidence_json' => 'array',
            'conclusion_codes_json' => 'array',
        ];
    }
}
