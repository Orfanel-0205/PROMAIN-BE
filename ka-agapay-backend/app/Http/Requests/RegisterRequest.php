<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'            => ['required', 'string', 'max:100'],
            'last_name'             => ['required', 'string', 'max:100'],
            'email'                 => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'mobile_number'         => [
                'required',
                'string',
                'regex:/^09\d{9}$/',
                'unique:users,mobile_number',
            ],
            'password'              => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required'],

            // Rule::exists() queries the actual DB at validation time.
            // Never use a hardcoded in: list — it will drift from the DB.
            'barangay'              => [
                'required',
                'string',
                'max:150',
                Rule::exists('barangays', 'name'),
            ],

            // Server-side age gate: birthday must be at least 18 years ago.
            // The mobile app enforces this too, but a direct API call would
            // bypass client-side checks without this rule.
            'birthday'              => [
                'nullable',
                'date',
                'before:' . now()->subYears(18)->toDateString(),
                'after:1900-01-01',
            ],

            'sex' => ['nullable', Rule::in(['male', 'female', 'other'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('barangay')) {
            $this->merge([
                'barangay' => trim((string) $this->input('barangay')),
            ]);
        }

        if ($this->has('mobile_number')) {
            $this->merge([
                'mobile_number' => trim((string) $this->input('mobile_number')),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'first_name.required'            => 'First name is required.',
            'last_name.required'             => 'Last name is required.',
            'mobile_number.required'         => 'Mobile number is required.',
            'mobile_number.regex'            => 'Mobile number must start with 09 and have 11 digits.',
            'mobile_number.unique'           => 'That mobile number is already registered.',
            'email.unique'                   => 'That email address is already registered.',
            'password.min'                   => 'Password must be at least 8 characters.',
            'password_confirmation.required' => 'Please confirm your password.',
            'barangay.required'              => 'Please select your barangay.',
            'barangay.exists'                => 'Please select a valid barangay from the list.',
            'birthday.before'                => 'You must be at least 18 years old to register.',
            'birthday.after'                 => 'Birthday must be after 1900.',
        ];
    }
}