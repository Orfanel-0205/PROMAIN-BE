<?php
// app/Http/Resources/Referral/ReferralResource.php

namespace App\Http\Resources\Referral;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'referral_type'  => $this->referral_type,
            'urgency'        => $this->urgency,
            'is_urgent'      => $this->is_urgent,

            'destination' => [
                'facility'   => $this->referred_facility,
                'department' => $this->referred_department,
                'physician'  => $this->referred_physician,
            ],

            'clinical' => [
                'reason'           => $this->reason,
                'clinical_summary' => $this->clinical_summary,
                'instructions'     => $this->instructions,
                'outcome_notes'    => $this->outcome_notes,
            ],

            'schedule' => [
                'follow_up_date' => $this->follow_up_date?->toDateString(),
                'follow_up_time' => $this->follow_up_time,
            ],

            'resident' => $this->whenLoaded('residentProfile', fn() => [
                'id'   => $this->residentProfile->id,
                'name' => optional($this->residentProfile->user)->first_name . ' '
                        . optional($this->residentProfile->user)->last_name,
            ]),

            'issued_by' => $this->whenLoaded('issuedBy', fn() => [
                'id'   => $this->issuedBy->user_id,
                'name' => $this->issuedBy->first_name . ' ' . $this->issuedBy->last_name,
            ]),

            'acknowledged_by' => $this->whenLoaded('acknowledgedBy', fn() => [
                'id'              => $this->acknowledgedBy->user_id,
                'name'            => $this->acknowledgedBy->first_name . ' ' . $this->acknowledgedBy->last_name,
                'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            ]),

            'assigned_bhw' => $this->whenLoaded('assignedBhw', fn() => [
                'id'   => $this->assignedBhw->user_id,
                'name' => $this->assignedBhw->first_name . ' ' . $this->assignedBhw->last_name,
            ]),

            'requires_bhw_monitoring' => $this->requires_bhw_monitoring,

            'source' => [
                'type' => class_basename($this->referable_type),
                'id'   => $this->referable_id,
            ],

            'timeline' => [
                'created_at'      => $this->created_at?->toIso8601String(),
                'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
                'completed_at'    => $this->completed_at?->toIso8601String(),
                'cancelled_at'    => $this->cancelled_at?->toIso8601String(),
            ],

            'cancellation_reason' => $this->cancellation_reason,

            'updates' => ReferralUpdateResource::collection($this->whenLoaded('updates')),
        ];
    }
}
