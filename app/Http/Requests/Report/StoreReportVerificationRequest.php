<?php

namespace App\Http\Requests\Report;

use App\Enums\PatientSex;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'patient_age_years' => ['required', 'numeric', 'gt:0', 'lte:130', 'decimal:0,2'],
            'patient_sex' => ['required', Rule::enum(PatientSex::class)],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.source_extracted_result_id' => ['nullable', 'integer', 'distinct'],
            'rows.*.label' => ['required', 'string', 'max:500'],
            'rows.*.value' => ['required', 'string', 'max:500'],
            'rows.*.unit' => ['nullable', 'string', 'max:255'],
            'rows.*.reference_range' => ['nullable', 'string', 'max:500'],
            'excluded_source_result_ids' => ['sometimes', 'array', 'max:200'],
            'excluded_source_result_ids.*' => ['integer', 'distinct'],
        ];
    }
}
