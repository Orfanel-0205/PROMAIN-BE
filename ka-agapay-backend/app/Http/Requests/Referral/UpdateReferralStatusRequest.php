<?php
// app/Http/Requests/Referral/UpdateReferralStatusRequest.php

namespace App\Http\Requests\Referral;

use App\Models\Referral;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReferralStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin', 'bhw']);
    }

    public function rules(): array
    {
        return [
            'status'               => ['required', 'string', 'in:' . implode(',', Referral::STATUSES)],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'outcome_notes'        => ['nullable', 'string', 'max:2000'],
            'cancellation_reason'  => [
                'required_if:status,cancelled',
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }
}
