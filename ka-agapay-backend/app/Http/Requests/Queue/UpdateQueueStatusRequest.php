<?php
// app/Http/Requests/Queue/UpdateQueueStatusRequest.php

namespace App\Http\Requests\Queue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQueueStatusRequest extends FormRequest
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
            'doctor',
            'nurse',
            'midwife',
            'bhw',
        ]) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    'waiting',
                    'called',
                    'in_service',
                    'completed',
                    'skipped',
                    'cancelled',
                    'no_show',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'cancellation_reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Please choose a queue action.',
            'status.in' => 'The selected queue action is invalid.',
        ];
    }
}