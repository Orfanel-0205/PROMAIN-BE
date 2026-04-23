<?php
// app/Http/Requests/Prescription/VoidPrescriptionRequest.php

namespace App\Http\Requests\Prescription;

use Illuminate\Foundation\Http\FormRequest;

class VoidPrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
