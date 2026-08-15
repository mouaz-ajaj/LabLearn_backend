<?php

namespace App\Http\Requests\Quiz;

use Illuminate\Foundation\Http\FormRequest;

class SubmitQuizAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_snapshot_id' => ['required', 'integer', 'min:1'],
            'selected_option_id' => ['required', 'string', 'max:64'],
        ];
    }
}
