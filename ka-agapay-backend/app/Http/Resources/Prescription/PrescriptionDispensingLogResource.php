<?php
// app/Http/Resources/Prescription/PrescriptionDispensingLogResource.php

namespace App\Http\Resources\Prescription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionDispensingLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'dispensed_items'    => $this->dispensed_items,
            'is_partial_dispense'=> $this->is_partial_dispense,
            'notes'              => $this->notes,
            'dispensed_by'       => $this->whenLoaded('dispensedBy', fn() => [
                'id'   => $this->dispensedBy->user_id,
                'name' => $this->dispensedBy->first_name . ' ' . $this->dispensedBy->last_name,
            ]),
            'dispensed_at'       => $this->dispensed_at?->toIso8601String(),
        ];
    }
}
