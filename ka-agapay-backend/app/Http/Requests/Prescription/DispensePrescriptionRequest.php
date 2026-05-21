<?php
// app/Http/Requests/Prescription/DispensePrescriptionRequest.php

namespace App\Http\Requests\Prescription;

use Illuminate\Foundation\Http\FormRequest;

class DispensePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['staff_admin', 'mho', 'super_admin']);
    }

    public function rules(): array
    {
        return [
            'dispensed_items'          => ['nullable', 'array'],
            'dispensed_items.*.name'   => ['required_with:dispensed_items', 'string'],
            'dispensed_items.*.quantity_dispensed' => ['required_with:dispensed_items', 'integer', 'min:1'],
            'is_partial_dispense'      => ['sometimes', 'boolean'],
            'notes'                    => ['nullable', 'string', 'max:500'],
        ];
    }
}
