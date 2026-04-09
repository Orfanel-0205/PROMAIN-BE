<?php
// app/Http/Requests/Telemedicine/CreateReferralRequest.php

namespace App\Http\Requests\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

class CreateReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'referral_type'  => ['required', 'string', 'in:follow_up,specialist,hospital,laboratory,bhw_monitoring'],
            'referred_to'    => ['nullable', 'string', 'max:255'],
            'reason'         => ['required', 'string', 'max:1000'],
            'instructions'   => ['nullable', 'string', 'max:1000'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
            'is_urgent'      => ['sometimes', 'boolean'],
        ];
    }
}