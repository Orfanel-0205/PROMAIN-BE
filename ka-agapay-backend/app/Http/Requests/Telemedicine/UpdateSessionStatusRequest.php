<?php
// app/Http/Requests/Telemedicine/UpdateSessionStatusRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSessionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([
            'admin',
            'staff',
            'staff_admin',
            'rhu_admin',
            'mho',
            'doctor',
            'nurse',
            'midwife',
            'super_admin',
        ]) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                'in:waiting,active,paused,ended,no_show,cancelled',
            ],

            'cancellation_reason' => [
                'required_if:status,cancelled',
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }
}