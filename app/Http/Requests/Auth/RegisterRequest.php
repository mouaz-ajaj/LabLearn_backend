<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [
            'name' => Str::squish($this->string('name')->toString()),
            'email' => Str::lower($this->string('email')->trim()->toString()),
        ];

        if ($this->has('study_year')) {
            $normalized['study_year'] = $this->string('study_year')->trim()->toString();
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'study_year' => [
                'prohibited_unless:role,'.UserRole::Student->value,
                'required_if:role,'.UserRole::Student->value,
                'string',
                Rule::in(User::STUDY_YEARS),
            ],
        ];
    }
}
