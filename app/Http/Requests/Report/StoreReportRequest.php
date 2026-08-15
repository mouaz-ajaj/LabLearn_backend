<?php

namespace App\Http\Requests\Report;

use App\Enums\ReportSourceType;
use App\Enums\ReportTestCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'test_category' => ['required', Rule::enum(ReportTestCategory::class)],
            'source_type' => ['required', Rule::enum(ReportSourceType::class)],
        ];
    }
}
