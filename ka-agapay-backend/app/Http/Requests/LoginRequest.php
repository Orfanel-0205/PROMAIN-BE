<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string'],
            'password'      => ['required', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::error('Login Validation Failed', [
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