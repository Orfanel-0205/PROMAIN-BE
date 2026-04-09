<?php
// app/Http/Requests/Telemedicine/ScreenTelemedicineRequestRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class ScreenTelemedicineRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['staff_admin', 'mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'decision'          => ['required', 'string', 'in:approve,reject'],
            'screening_notes'   => ['nullable', 'string', 'max:1000'],
            'rejection_reason'  => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
            // Only when approving — schedule the session immediately or leave for later
            'schedule_now'      => ['sometimes', 'boolean'],
            'assigned_doctor_id'=> ['required_if:schedule_now,true', 'nullable', 'integer', 'exists:users,user_id'],
            'scheduled_date'    => ['required_if:schedule_now,true', 'nullable', 'date', 'after_or_equal:today'],
            'scheduled_time'    => ['required_if:schedule_now,true', 'nullable', 'date_format:H:i'],
            'session_mode'      => ['sometimes', 'string', 'in:video_call,voice_call,chat,in_app'],
        ];
    }
}