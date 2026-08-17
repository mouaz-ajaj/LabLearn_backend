<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestResultExplanationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 'role' is deliberately NOT accepted here - the backend always derives
            // it from the authenticated user, so a client can never impersonate a
            // different role to request a different explanation depth.
            'language' => ['required', 'string', Rule::in(['ar', 'en'])],
        ];
    }
}
