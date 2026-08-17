<?php

namespace App\Http\Requests\Comparison;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'report_ids' => ['required', 'array', 'min:2', 'max:10'],
            'report_ids.*' => ['integer', 'distinct', 'min:1'],
            'language' => ['required', 'string', Rule::in(['ar', 'en'])],
        ];
    }
}
