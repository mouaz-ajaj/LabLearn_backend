<?php

namespace App\Http\Requests\Report;

use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'test_category' => ['sometimes', Rule::enum(ReportTestCategory::class)],
            'status' => ['sometimes', Rule::enum(ReportStatus::class)],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 10);
    }
}
