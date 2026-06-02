<?php
// app/Http/Requests/Telemedicine/ScheduleSessionRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleSessionRequest extends FormRequest
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
            'assigned_doctor_id' => [
                'required',
                'integer',
                'exists:users,user_id',
            ],

            'scheduled_date' => [
                'required',
                'date',
                'after_or_equal:today',
            ],

            'scheduled_time' => [
                'required',
                'date_format:H:i',
            ],

            'estimated_duration_minutes' => [
                'nullable',
                'integer',
                'min:5',
                'max:120',
            ],

            'session_mode' => [
                'sometimes',
                'string',
                'in:video_call,voice_call,chat,in_app',
            ],

            'bhw_companion_id' => [
                'nullable',
                'integer',
                'exists:users,user_id',
            ],
        ];
    }
}