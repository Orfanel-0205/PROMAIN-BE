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
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['required', 'string', 'max:100'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'mobile_number' => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile_number'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'barangay_id'   => ['nullable', 'integer'],
            'role'          => ['nullable', 'string'],
        ];
    }
}