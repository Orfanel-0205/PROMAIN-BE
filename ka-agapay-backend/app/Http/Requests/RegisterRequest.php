<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'mobile_number' => 'required|string|max:20|unique:users,mobile_number',
            'email'         => 'nullable|email|unique:users,email',
            'password'      => [
                'required',
                'string',
                'min:8',
                'confirmed',
                \Illuminate\Validation\Rules\Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->uncompromised(),
            ],
            'barangay_id'   => 'nullable|exists:barangays,barangay_id',
        ];
    }
}