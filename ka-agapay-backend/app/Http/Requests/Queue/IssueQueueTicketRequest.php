<?php
// app/Http/Requests/Queue/IssueQueueTicketRequest.php

namespace App\Http\Requests\Queue;

use Illuminate\Foundation\Http\FormRequest;

class IssueQueueTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Staff, BHW, Admin, or the resident themselves (self-service kiosk mode)
        return $this->user()->hasAnyRole(['admin', 'staff', 'bhw', 'mho', 'super_admin', 'resident']);
    }

    public function rules(): array
    {
        return [
            'resident_profile_id' => ['required', 'integer', 'exists:resident_profiles,id'],
            'rhu_id'              => ['required', 'integer', 'exists:barangays,id'],
            'service_type'        => ['required', 'string', 'in:opd_consultation,prenatal_checkup,immunization,family_planning,tb_dots,laboratory,dental,emergency,medicine_release,bhw_assisted'],
            'appointment_id'      => ['nullable', 'integer', 'exists:appointments,id'],
            'is_emergency'        => ['sometimes', 'boolean'],
            'is_bhw_endorsed'     => ['sometimes', 'boolean'],
            'notes'               => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'resident_profile_id.exists' => 'The specified resident profile does not exist.',
            'rhu_id.exists'              => 'The specified RHU is not recognized.',
            'service_type.in'            => 'The selected service type is not available.',
        ];
    }
}