<?php
// app/Http/Resources/Prescription/PrescriptionResource.php

namespace App\Http\Resources\Prescription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'prescription_number'  => $this->prescription_number,
            'status'               => $this->status,
            'rhu_id'               => $this->rhu_id,

            'dates' => [
                'prescription_date' => $this->prescription_date?->toDateString(),
                'valid_until'       => $this->valid_until?->toDateString(),
                'is_expired'        => $this->isExpired(),
                'dispensed_at'      => $this->dispensed_at?->toIso8601String(),
                'voided_at'         => $this->voided_at?->toIso8601String(),
            ],

            'clinical' => [
                'diagnosis'       => $this->diagnosis,
                'diagnosis_code'  => $this->diagnosis_code,
                'medications'     => $this->medications,
                'medication_count'=> is_array($this->medications) ? count($this->medications) : 0,
                'has_controlled_substances' => $this->has_controlled_substances,
                'additional_instructions'   => $this->additional_instructions,
                'dispensing_notes'          => $this->dispensing_notes,
            ],

            'resident' => $this->whenLoaded('residentProfile', fn() => [
                'id'   => $this->residentProfile->id,
                'name' => optional($this->residentProfile->user)->first_name . ' '
                        . optional($this->residentProfile->user)->last_name,
            ]),

            'prescribed_by' => $this->whenLoaded('prescribedBy', fn() => [
                'id'   => $this->prescribedBy->user_id,
                'name' => $this->prescribedBy->first_name . ' ' . $this->prescribedBy->last_name,
            ]),

            'dispensed_by' => $this->whenLoaded('dispensedBy', fn() => [
                'id'   => $this->dispensedBy->user_id,
                'name' => $this->dispensedBy->first_name . ' ' . $this->dispensedBy->last_name,
            ]),

            'voided_by' => $this->whenLoaded('voidedBy', fn() => [
                'id'        => $this->voidedBy->user_id,
                'name'      => $this->voidedBy->first_name . ' ' . $this->voidedBy->last_name,
                'void_reason' => $this->void_reason,
            ]),

            'source' => [
                'consultation_id'         => $this->consultation_id,
                'telemedicine_session_id' => $this->telemedicine_session_id,
            ],

            'dispensing_logs' => PrescriptionDispensingLogResource::collection(
                $this->whenLoaded('dispensingLogs')
            ),

            'file_path' => $this->file_path,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
