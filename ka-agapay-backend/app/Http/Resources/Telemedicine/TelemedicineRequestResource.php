<?php
// app/Http/Resources/Telemedicine/TelemedicineRequestResource.php

namespace App\Http\Resources\Telemedicine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelemedicineRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $residentProfile = $this->resource->relationLoaded('residentProfile')
            ? $this->residentProfile
            : null;

        $residentUser = $residentProfile?->user;

        $residentName = trim(
            ($residentUser?->first_name ?? '') . ' ' .
            ($residentUser?->last_name ?? '')
        );

        if ($residentName === '') {
            $residentName = $residentUser?->name
                ?? $residentUser?->full_name
                ?? "Patient Request #{$this->id}";
        }

        $rhu = $this->resource->relationLoaded('rhu')
            ? $this->rhu
            : null;

        $endorsedByBhw = $this->resource->relationLoaded('endorsedByBhw')
            ? $this->endorsedByBhw
            : null;

        $screenedBy = $this->resource->relationLoaded('screenedBy')
            ? $this->screenedBy
            : null;

        $endorsedTo = $this->resource->relationLoaded('endorsedTo')
            ? $this->endorsedTo
            : null;

        $requestedBy = $this->resource->relationLoaded('requestedBy')
            ? $this->requestedBy
            : null;

        $queueTicket = $this->resource->relationLoaded('queueTicket')
            ? $this->queueTicket
            : null;

        $session = $this->resource->relationLoaded('session')
            ? $this->session
            : null;

        return [
            'id'                 => $this->id,
            'status'             => $this->status,
            'urgency_level'      => $this->urgency_level,
            'chief_complaint'    => $this->chief_complaint,
            'symptoms'           => $this->symptoms,
            'additional_notes'   => $this->additional_notes,

            'resident_profile_id' => $this->resident_profile_id,
            'requested_by_id'     => $this->requested_by,
            'rhu_id'              => $this->rhu_id,
            'queue_ticket_id'     => $this->queue_ticket_id,
            'appointment_id'      => $this->appointment_id,

            'resident' => $residentProfile ? [
                'id'       => $residentProfile->id,
                'user_id'  => $residentProfile->user_id,
                'name'     => $residentName,
                'barangay' => $residentProfile->barangay?->name,
            ] : null,

            'rhu' => $rhu ? [
                'id'          => $rhu->barangay_id ?? $rhu->id ?? null,
                'barangay_id' => $rhu->barangay_id ?? null,
                'name'        => $rhu->name ?? null,
            ] : null,

            'bhw_assistance' => [
                'is_assisted' => (bool) $this->is_bhw_assisted,
                'endorsed_by' => $endorsedByBhw ? [
                    'id'   => $endorsedByBhw->user_id,
                    'name' => trim(
                        ($endorsedByBhw->first_name ?? '') . ' ' .
                        ($endorsedByBhw->last_name ?? '')
                    ) ?: ($endorsedByBhw->name ?? null),
                ] : null,
                'bhw_notes' => $this->bhw_notes,
            ],

            'screening' => [
                'screened_by' => $screenedBy ? [
                    'id'   => $screenedBy->user_id,
                    'name' => trim(
                        ($screenedBy->first_name ?? '') . ' ' .
                        ($screenedBy->last_name ?? '')
                    ) ?: ($screenedBy->name ?? null),
                ] : null,
                'screening_notes' => $this->screening_notes,
                'screened_at'     => optional($this->screened_at)->toIso8601String(),
                'vitals'          => [
                    'temperature'      => $this->vital_temperature,
                    'blood_pressure'   => $this->vital_bp,
                    'heart_rate'       => $this->vital_heart_rate,
                    'respiratory_rate' => $this->vital_respiratory_rate,
                ],
            ],

            'endorsement' => [
                'endorsed_to' => $endorsedTo ? [
                    'id'   => $endorsedTo->user_id,
                    'name' => trim(
                        ($endorsedTo->first_name ?? '') . ' ' .
                        ($endorsedTo->last_name ?? '')
                    ) ?: ($endorsedTo->name ?? null),
                ] : null,
                'endorsed_at' => optional($this->endorsed_at)->toIso8601String(),
            ],

            'requested_by' => $requestedBy ? [
                'id'   => $requestedBy->user_id,
                'name' => trim(
                    ($requestedBy->first_name ?? '') . ' ' .
                    ($requestedBy->last_name ?? '')
                ) ?: ($requestedBy->name ?? null),
            ] : null,

            'queue_ticket' => $queueTicket ? [
                'id'            => $queueTicket->id ?? null,
                'ticket_number' => $queueTicket->ticket_number ?? null,
                'status'        => $queueTicket->status ?? null,
            ] : null,

            'rejection_reason'    => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at'        => optional($this->cancelled_at)->toIso8601String(),

            // IMPORTANT:
            // Return null safely when no session exists yet.
            // Do not instantiate TelemedicineSessionResource with null/MissingValue.
            'session' => $session
                ? new TelemedicineSessionResource($session)
                : null,

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
