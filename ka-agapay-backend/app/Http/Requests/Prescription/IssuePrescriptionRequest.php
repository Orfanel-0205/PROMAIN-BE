<?php
// app/Http/Requests/Prescription/IssuePrescriptionRequest.php

namespace App\Http\Requests\Prescription;

use App\Models\Prescription;
use Illuminate\Foundation\Http\FormRequest;

class IssuePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'resident_profile_id'      => ['required', 'integer', 'exists:resident_profiles,id'],
            'rhu_id'                   => ['required', 'integer'],
            'consultation_id'          => ['nullable', 'integer', 'exists:consultations,id'],
            'telemedicine_session_id'  => ['nullable', 'integer', 'exists:telemedicine_sessions,id'],
            'diagnosis'                => ['nullable', 'string', 'max:500'],
            'diagnosis_code'           => ['nullable', 'string', 'max:20'],

            // Medications array validation
            'medications'              => ['required', 'array', 'min:1'],
            'medications.*.name'       => ['required', 'string', 'max:150'],
            'medications.*.generic_name' => ['nullable', 'string', 'max:150'],
            'medications.*.dosage'     => ['required', 'string', 'max:50'],
            'medications.*.dosage_form'=> ['required', 'string', 'max:50'],
            'medications.*.quantity'   => ['required', 'integer', 'min:1'],
            'medications.*.frequency'  => ['required', 'string', 'max:50'],
            'medications.*.duration'   => ['required', 'string', 'max:50'],
            'medications.*.route'      => ['nullable', 'string', 'max:50'],
            'medications.*.instructions' => ['nullable', 'string', 'max:300'],
            'medications.*.is_controlled' => ['sometimes', 'boolean'],
            'medications.*.brand_alternatives_allowed' => ['sometimes', 'boolean'],

            // Required only when issuing controlled substances
            's2_license_number'        => ['nullable', 'string', 'max:50'],
            'additional_instructions'  => ['nullable', 'string', 'max:1000'],
            'dispensing_notes'         => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasControlled = collect($this->medications ?? [])
                ->contains(fn($m) => !empty($m['is_controlled']));

            if ($hasControlled && empty($this->s2_license_number)) {
                $validator->errors()->add(
                    's2_license_number',
                    'S2 license number is required when prescribing controlled substances.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'medications.required'      => 'At least one medication is required.',
            'medications.*.name.required' => 'Each medication must have a name.',
            'medications.*.dosage.required' => 'Each medication must have a dosage.',
        ];
    }
}
