<?php

namespace App\Models;

use App\Enums\PatientSex;
use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'test_category', 'source_type', 'status', 'report_date', 'patient_age_years', 'patient_sex'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ReportStatus::Uploaded->value,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function file(): HasOne
    {
        return $this->hasOne(ReportFile::class);
    }

    public function extractionJobs(): HasMany
    {
        return $this->hasMany(ExtractionJob::class);
    }

    public function extractedResults(): HasMany
    {
        return $this->hasMany(ExtractedResult::class);
    }

    public function verifiedResultSets(): HasMany
    {
        return $this->hasMany(VerifiedResultSet::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    public function quizSessions(): HasMany
    {
        return $this->hasMany(QuizSession::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'test_category' => ReportTestCategory::class,
            'source_type' => ReportSourceType::class,
            'status' => ReportStatus::class,
            'report_date' => 'date',
            'patient_age_years' => 'float',
            'patient_sex' => PatientSex::class,
        ];
    }
}
