<?php

namespace App\Models;

use Database\Factories\ReportFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'report_id',
    'original_name',
    'storage_disk',
    'storage_path',
    'mime_type',
    'extension',
    'size_bytes',
    'checksum',
])]
class ReportFile extends Model
{
    /** @use HasFactory<ReportFileFactory> */
    use HasFactory;

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function extractionJobs(): HasMany
    {
        return $this->hasMany(ExtractionJob::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
