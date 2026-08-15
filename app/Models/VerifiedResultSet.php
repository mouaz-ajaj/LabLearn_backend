<?php

namespace App\Models;

use App\Enums\PatientSex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'report_id', 'version', 'confirmed_by_user_id', 'patient_age_years', 'patient_sex',
    'idempotency_key', 'excluded_source_result_ids', 'category_gate_status',
    'category_gate_category', 'category_gate_evidence', 'confirmed_at',
])]
class VerifiedResultSet extends Model
{
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(VerifiedResult::class)->orderBy('display_order');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'patient_age_years' => 'float',
            'patient_sex' => PatientSex::class,
            'excluded_source_result_ids' => 'array',
            'category_gate_evidence' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
