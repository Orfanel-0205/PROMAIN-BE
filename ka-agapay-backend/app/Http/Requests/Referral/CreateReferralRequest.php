<?php
// app/Http/Requests/Referral/CreateReferralRequest.php

namespace App\Http\Requests\Referral;

use App\Models\Referral;
use Illuminate\Foundation\Http\FormRequest;

class CreateReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin', 'bhw']);
    }

    public function rules(): array
    {
        return [
            'referable_type'          => ['required', 'string', 'in:consultation,telemedicine_session,bhw_assessment'],
            'referable_id'            => ['required', 'integer'],
            'resident_profile_id'     => ['required', 'integer', 'exists:resident_profiles,id'],
            'referral_type'           => ['required', 'string', 'in:' . implode(',', Referral::TYPES)],
            'referred_facility'       => ['nullable', 'string', 'max:255'],
            'referred_department'     => ['nullable', 'string', 'max:100'],
            'referred_physician'      => ['nullable', 'string', 'max:150'],
            'reason'                  => ['required', 'string', 'min:10', 'max:1000'],
            'clinical_summary'        => ['nullable', 'string', 'max:2000'],
            'instructions'            => ['nullable', 'string', 'max:1000'],
            'urgency'                 => ['sometimes', 'string', 'in:' . implode(',', Referral::URGENCY)],
            'follow_up_date'          => ['nullable', 'date', 'after_or_equal:today'],
            'follow_up_time'          => ['nullable', 'date_format:H:i'],
            'assigned_bhw_id'         => ['nullable', 'integer', 'exists:users,user_id'],
            'requires_bhw_monitoring' => ['sometimes', 'boolean'],
        ];
    }
}
