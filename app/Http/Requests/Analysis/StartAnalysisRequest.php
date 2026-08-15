<?php

namespace App\Http\Requests\Analysis;

use App\Enums\AnalysisFlow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verified_result_set_id' => ['required', 'integer', 'min:1'],
            'flow' => ['required', 'string', Rule::enum(AnalysisFlow::class)],
        ];
    }
}
