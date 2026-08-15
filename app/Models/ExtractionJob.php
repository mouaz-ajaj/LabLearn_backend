<?php

namespace App\Models;

use App\Enums\ExtractionJobStatus;
use Database\Factories\ExtractionJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'report_id',
    'report_file_id',
    'status',
    'progress',
    'current_step',
    'attempts',
    'engine_name',
    'engine_version',
    'started_at',
    'completed_at',
    'failed_at',
    'error_code',
    'safe_error_message',
    'raw_response_json',
])]
class ExtractionJob extends Model
{
    /** @use HasFactory<ExtractionJobFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ExtractionJobStatus::Queued->value,
        'progress' => 0,
        'current_step' => 'QUEUED',
        'attempts' => 0,
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function reportFile(): BelongsTo
    {
        return $this->belongsTo(ReportFile::class);
    }

    public function extractedResults(): HasMany
    {
        return $this->hasMany(ExtractedResult::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExtractionJobStatus::class,
            'progress' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'raw_response_json' => 'array',
        ];
    }
}
