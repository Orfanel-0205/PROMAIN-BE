<?php
// app/Http/Resources/Telemedicine/TelemedicineSessionResource.php

namespace App\Http\Resources\Telemedicine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelemedicineSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'session_mode'   => $this->session_mode,
            'session_token'  => $this->session_token,
            'session_link'   => $this->session_link,

            'schedule' => [
                'date'                       => $this->scheduled_date?->toDateString(),
                'time'                       => $this->scheduled_time,
                'estimated_duration_minutes' => $this->estimated_duration_minutes,
            ],

            'assigned_doctor' => $this->whenLoaded('assignedDoctor', fn() => [
                'id'   => $this->assignedDoctor->user_id,
                'name' => $this->assignedDoctor->first_name . ' ' . $this->assignedDoctor->last_name,
            ]),

            'bhw_companion' => $this->whenLoaded('bhwCompanion', fn() => [
                'id'   => $this->bhwCompanion->user_id,
                'name' => $this->bhwCompanion->first_name . ' ' . $this->bhwCompanion->last_name,
            ]),

            'timeline' => [
                'started_at'              => $this->started_at?->toIso8601String(),
                'ended_at'                => $this->ended_at?->toIso8601String(),
                'actual_duration_minutes' => $this->actual_duration_minutes,
            ],

            'consultation_id'    => $this->consultation_id,
            'cancellation_reason'=> $this->cancellation_reason,

            'notes'    => new TelemedicineSessionNoteResource($this->whenLoaded('notes')),
            'referrals'=> TelemedicineReferralResource::collection($this->whenLoaded('referrals')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}