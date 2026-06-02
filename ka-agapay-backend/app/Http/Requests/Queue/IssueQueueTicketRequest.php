<?php
// app/Http/Requests/Queue/IssueQueueTicketRequest.php

namespace App\Http\Requests\Queue;

use Illuminate\Foundation\Http\FormRequest;

class IssueQueueTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Staff, BHW, Admin, MHO, Super Admin, or Resident self-service
        return $this->user()?->hasAnyRole([
            'admin',
            'staff',
            'staff_admin',
            'rhu_admin',
            'bhw',
            'mho',
            'super_admin',
            'resident',
        ]) ?? false;
    }

    public function rules(): array
    {
        return [
            'resident_profile_id' => [
                'required',
                'integer',
                'exists:resident_profiles,id',
            ],

            // IMPORTANT:
            // Your queue_tickets.rhu_id references barangays.barangay_id,
            // not barangays.id.
            'rhu_id' => [
                'required',
                'integer',
                'exists:barangays,barangay_id',
            ],

            'service_type' => [
                'required',
                'string',
                'in:opd_consultation,prenatal_checkup,immunization,family_planning,tb_dots,laboratory,dental,emergency,medicine_release,bhw_assisted',
            ],

            'appointment_id' => [
                'nullable',
                'integer',
                'exists:appointments,id',
            ],

            'is_emergency' => [
                'sometimes',
                'boolean',
            ],

            'is_bhw_endorsed' => [
                'sometimes',
                'boolean',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'resident_profile_id.required' => 'Please select a resident profile.',
            'resident_profile_id.exists'   => 'The specified resident profile does not exist.',

            'rhu_id.required' => 'Please select an RHU.',
            'rhu_id.exists'   => 'The specified RHU is not recognized.',

            'service_type.required' => 'Please select a service type.',
            'service_type.in'       => 'The selected service type is not available.',

            'appointment_id.exists' => 'The selected appointment does not exist.',
        ];
    }
}