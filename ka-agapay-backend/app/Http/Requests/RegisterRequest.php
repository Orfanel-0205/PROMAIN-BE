<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

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
            'email'                 => ['required', 'email', 'unique:users,email'],
            'mobile_number'         => ['required', 'string', 'unique:users,mobile_number'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'barangay_id'           => ['nullable', 'integer', 'exists:barangays,barangay_id'],
            'role'                  => ['nullable', 'string'],
        ];
    }

    // Always return JSON errors, never redirect
    protected function failedValidation(Validator $validator): void
    {
        Log::error('Registration Validation Failed', [
            'errors' => $validator->errors()->toArray(),
            'request' => $this->all(),
        ]);

        throw new ValidationException($validator,
            response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}