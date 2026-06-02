<?php
// app/Http/Requests/Queue/QueueListRequest.php

namespace App\Http\Requests\Queue;

use Illuminate\Foundation\Http\FormRequest;

class QueueListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole([
            'admin',
            'staff',
            'staff_admin',
            'rhu_admin',
            'mho',
            'super_admin',
            'bhw',
        ]) ?? false;
    }

    public function rules(): array
    {
        return [
            // IMPORTANT:
            // Your queue_tickets.rhu_id references barangays.barangay_id,
            // not barangays.id.
            'rhu_id' => [
                'required',
                'integer',
                'exists:barangays,barangay_id',
            ],

            'service_type' => [
                'nullable',
                'string',
                'in:opd_consultation,prenatal_checkup,immunization,family_planning,tb_dots,laboratory,dental,emergency,medicine_release,bhw_assisted',
            ],

            'status' => [
                'nullable',
                'string',
                'in:waiting,called,in_service,completed,skipped,cancelled,no_show',
            ],

            'date' => [
                'nullable',
                'date',
                'date_format:Y-m-d',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:5',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'rhu_id.required' => 'Please select an RHU.',
            'rhu_id.exists'   => 'The specified RHU is not recognized.',

            'service_type.in' => 'The selected service type is not available.',
            'status.in'       => 'The selected queue status is invalid.',
            'date.date_format'=> 'The date must be in YYYY-MM-DD format.',
        ];
    }
}