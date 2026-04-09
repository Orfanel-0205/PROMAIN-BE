<?php
// app/Http/Requests/Telemedicine/SaveSessionNotesRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class SaveSessionNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'subjective'               => ['nullable', 'string'],
            'objective'                => ['nullable', 'string'],
            'assessment'               => ['nullable', 'string'],
            'plan'                     => ['nullable', 'string'],
            'primary_diagnosis_code'   => ['nullable', 'string', 'max:20'],
            'primary_diagnosis_label'  => ['nullable', 'string', 'max:255'],
            'medications'              => ['nullable', 'array'],
            'medications.*.name'       => ['required', 'string', 'max:100'],
            'medications.*.dosage'     => ['nullable', 'string', 'max:100'],
            'medications.*.frequency'  => ['nullable', 'string', 'max:100'],
            'medications.*.duration'   => ['nullable', 'string', 'max:100'],
            'finalize'                 => ['sometimes', 'boolean'],
        ];
    }
}