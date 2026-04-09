<?php
// app/Http/Requests/Telemedicine/CreateTelemedicineRequestRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class CreateTelemedicineRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['resident', 'bhw', 'staff_admin', 'mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'resident_profile_id' => ['required', 'integer', 'exists:resident_profiles,id'],
            'rhu_id'              => ['required', 'integer', 'exists:barangays,barangay_id'],
            'chief_complaint'     => ['required', 'string', 'max:1000'],
            'urgency_level'       => ['sometimes', 'string', 'in:routine,urgent,emergency'],
            'symptoms'            => ['nullable', 'array'],
            'symptoms.*'          => ['string', 'max:100'],
            'additional_notes'    => ['nullable', 'string', 'max:1000'],
            'queue_ticket_id'     => ['nullable', 'integer', 'exists:queue_tickets,id'],
            'appointment_id'      => ['nullable', 'integer', 'exists:appointments,id'],
            // BHW endorsement fields (only when submitted by BHW)
            'is_bhw_assisted'     => ['sometimes', 'boolean'],
            'bhw_notes'           => ['nullable', 'string', 'max:500'],
        ];
    }
}