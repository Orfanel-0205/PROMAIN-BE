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
            'mobile_number' => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:users,mobile_number'],
            'email'         => 'nullable|email|unique:users,email',
            'password'      => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],
            'barangay_id'   => 'nullable|exists:barangays,barangay_id',
        ];
    }
}