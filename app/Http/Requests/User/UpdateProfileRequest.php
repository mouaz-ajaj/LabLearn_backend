<?php

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('name')) {
            $normalized['name'] = Str::squish($this->string('name')->toString());
        }

        if ($this->has('study_year')) {
            $normalized['study_year'] = $this->string('study_year')->trim()->toString();
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isStudent = $this->user()?->role === UserRole::Student;

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'study_year' => [
                'sometimes',
                Rule::prohibitedIf(! $isStudent),
                'string',
                Rule::in(User::STUDY_YEARS),
            ],
            'id' => ['prohibited'],
            'email' => ['prohibited'],
            'password' => ['prohibited'],
            'role' => ['prohibited'],
        ];
    }
}
