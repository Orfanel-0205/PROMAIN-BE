<?php
// app/Http/Requests/Queue/UpdateQueueStatusRequest.php

namespace App\Http\Requests\Queue;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQueueStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['admin', 'staff', 'mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'status'               => ['required', 'string', 'in:called,in_service,completed,skipped,cancelled,no_show'],
            'cancellation_reason'  => ['required_if:status,cancelled', 'nullable', 'string', 'max:300'],
            'notes'                => ['nullable', 'string', 'max:500'],
        ];
    }
}