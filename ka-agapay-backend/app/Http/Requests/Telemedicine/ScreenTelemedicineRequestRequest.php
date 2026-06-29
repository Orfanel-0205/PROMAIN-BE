<?php
// app/Http/Requests/Telemedicine/ScreenTelemedicineRequestRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class ScreenTelemedicineRequestRequest extends FormRequest
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
            'head_nurse',
            'super_admin',
        ]) ?? false;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approve,reject'],

            'screening_notes' => ['nullable', 'string', 'max:1000'],

            'rejection_reason' => [
                'required_if:decision,reject',
                'nullable',
                'string',
                'max:500',
            ],

            // Vital signs collected at screening time (Level 1 staff)
            'vital_temperature'      => ['nullable', 'string', 'max:20'],
            'vital_bp'               => ['nullable', 'string', 'max:30'],
            'vital_heart_rate'       => ['nullable', 'string', 'max:20'],
            'vital_respiratory_rate' => ['nullable', 'string', 'max:20'],

            'schedule_now' => ['sometimes', 'boolean'],

            'assigned_doctor_id' => [
                'required_if:schedule_now,true',
                'nullable',
                'integer',
                'exists:users,user_id',
            ],

            'scheduled_date' => [
                'required_if:schedule_now,true',
                'nullable',
                'date',
                'after_or_equal:today',
            ],

            'scheduled_time' => [
                'required_if:schedule_now,true',
                'nullable',
                'date_format:H:i',
            ],

            'session_mode' => [
                'sometimes',
                'string',
                'in:video_call,voice_call,chat,in_app',
            ],
        ];
    }
}
