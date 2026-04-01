<?php
// app/Http/Requests/Queue/QueueListRequest.php

namespace App\Http\Requests\Queue;

use Illuminate\Foundation\Http\FormRequest;

class QueueListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['admin', 'staff', 'mho', 'super_admin', 'bhw']);
    }

    public function rules(): array
    {
        return [
            'rhu_id'       => ['required', 'integer', 'exists:barangays,id'],
            'service_type' => ['nullable', 'string', 'in:opd_consultation,prenatal_checkup,immunization,family_planning,tb_dots,laboratory,dental,emergency,medicine_release,bhw_assisted'],
            'status'       => ['nullable', 'string', 'in:waiting,called,in_service,completed,skipped,cancelled,no_show'],
            'date'         => ['nullable', 'date', 'date_format:Y-m-d'],
            'per_page'     => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}