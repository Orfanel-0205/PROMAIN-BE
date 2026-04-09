<?php
// app/Http/Resources/Telemedicine/TelemedicineSessionNoteResource.php

namespace App\Http\Resources\Telemedicine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelemedicineSessionNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'subjective'              => $this->subjective,
            'objective'               => $this->objective,
            'assessment'              => $this->assessment,
            'plan'                    => $this->plan,
            'primary_diagnosis_code'  => $this->primary_diagnosis_code,
            'primary_diagnosis_label' => $this->primary_diagnosis_label,
            'medications'             => $this->medications,
            'is_finalized'            => $this->is_finalized,
            'finalized_at'            => $this->finalized_at?->toIso8601String(),
            'recorded_by' => $this->whenLoaded('recordedBy', fn() => [
                'id'   => $this->recordedBy->user_id,
                'name' => $this->recordedBy->first_name . ' ' . $this->recordedBy->last_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}