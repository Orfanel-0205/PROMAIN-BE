<?php
// app/Http/Resources/Telemedicine/TelemedicineRequestResource.php

namespace App\Http\Resources\Telemedicine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelemedicineRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'urgency_level'  => $this->urgency_level,
            'chief_complaint'=> $this->chief_complaint,
            'symptoms'       => $this->symptoms,
            'additional_notes' => $this->additional_notes,

            'resident' => $this->whenLoaded('residentProfile', fn() => [
                'id'       => $this->residentProfile->id,
                'name'     => trim(
                    ($this->residentProfile->user->first_name ?? '') . ' ' .
                    ($this->residentProfile->user->last_name ?? '')
                ),
                'barangay' => $this->residentProfile->barangay?->name,
            ]),

            'rhu' => $this->whenLoaded('rhu', fn() => [
                'id'   => $this->rhu->barangay_id,
                'name' => $this->rhu->name,
            ]),

            'bhw_assistance' => [
                'is_assisted'    => $this->is_bhw_assisted,
                'endorsed_by'    => $this->whenLoaded('endorsedByBhw', fn() => [
                    'id'   => $this->endorsedByBhw->user_id,
                    'name' => $this->endorsedByBhw->first_name . ' ' . $this->endorsedByBhw->last_name,
                ]),
                'bhw_notes'      => $this->bhw_notes,
            ],

            'screening' => [
                'screened_by'    => $this->whenLoaded('screenedBy', fn() => [
                    'id'   => $this->screenedBy->user_id,
                    'name' => $this->screenedBy->first_name . ' ' . $this->screenedBy->last_name,
                ]),
                'screening_notes'  => $this->screening_notes,
                'screened_at'      => $this->screened_at?->toIso8601String(),
            ],

            'rejection_reason'   => $this->rejection_reason,
            'cancellation_reason'=> $this->cancellation_reason,
            'cancelled_at'       => $this->cancelled_at?->toIso8601String(),

            'session' => new TelemedicineSessionResource($this->whenLoaded('session')),

            'requested_by' => $this->whenLoaded('requestedBy', fn() => [
                'id'   => $this->requestedBy->user_id,
                'name' => $this->requestedBy->first_name . ' ' . $this->requestedBy->last_name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}