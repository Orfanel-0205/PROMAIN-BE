<?php

namespace App\Http\Requests;

use App\Support\BarangayList;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['required', 'string', 'max:100'],
            'email'         => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'mobile_number' => ['required', 'string', 'max:20', 'unique:users,mobile_number'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'barangay'      => ['required', 'string', BarangayList::validationRule()],
            'birthday'      => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'sex'           => ['nullable', 'in:male,female,other'],
            // barangay_id removed — not needed anymore
        ];
    }

    public function messages(): array
    {
        return [
            'barangay.required' => 'Please select your barangay.',
            'barangay.in'       => 'The selected barangay is not in the list.',
            'birthday.date'     => 'Please enter a valid birthday.',
            'birthday.before'   => 'Birthday must be in the past.',
        ];
    }
}