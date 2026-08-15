<?php

namespace App\Models;

use Database\Factories\AnalysisConclusionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['analysis_id', 'conclusion_code', 'level', 'title_json', 'summary_json', 'evidence_json', 'rule_codes_json', 'display_order'])]
class AnalysisConclusion extends Model
{
    /** @use HasFactory<AnalysisConclusionFactory> */
    use HasFactory;

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    protected function casts(): array
    {
        return [
            'title_json' => 'array',
            'summary_json' => 'array',
            'evidence_json' => 'array',
            'rule_codes_json' => 'array',
            'display_order' => 'integer',
        ];
    }
}
