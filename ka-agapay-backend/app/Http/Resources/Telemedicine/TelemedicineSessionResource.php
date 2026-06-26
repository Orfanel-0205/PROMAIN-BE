<?php
// app/Http/Resources/Telemedicine/TelemedicineSessionResource.php

namespace App\Http\Resources\Telemedicine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelemedicineSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (!$this->resource) {
            return [];
        }

        $assignedDoctor = $this->resource->relationLoaded('assignedDoctor')
            ? $this->assignedDoctor
            : null;

        $bhwCompanion = $this->resource->relationLoaded('bhwCompanion')
            ? $this->bhwCompanion
            : null;

        $notes = $this->resource->relationLoaded('notes')
            ? $this->notes
            : null;

        $referrals = $this->resource->relationLoaded('referrals')
            ? $this->referrals
            : collect();

        $telemedicineRequest = $this->resource->relationLoaded('request')
            ? $this->request
            : null;

        $residentProfile = $telemedicineRequest?->relationLoaded('residentProfile')
            ? $telemedicineRequest->residentProfile
            : null;

        $residentUser = $residentProfile?->user;

        $residentName = trim(
            ($residentUser?->first_name ?? '') . ' ' .
            ($residentUser?->last_name ?? '')
        );

        if ($residentName === '') {
            $residentName = $residentUser?->name
                ?? $residentUser?->full_name
                ?? null;
        }

        $roomUrl = '/telemedicine/room/' . $this->id;

        $roomName = $this->room_id
            ?: $this->session_link
            ?: $this->session_token
            ?: 'kaagapay-rhu-session-' . $this->id;

        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'assigned_doctor_id' => $this->assigned_doctor_id,
            'bhw_companion_id' => $this->bhw_companion_id,

            'status' => $this->status,
            'session_mode' => $this->session_mode,
            'session_link' => $this->session_link,
            'session_token' => $this->session_token,

            'room_id' => $this->room_id,
            'room_token' => $this->room_token,
            'ice_servers' => $this->ice_servers,

            'room_name' => $roomName,
            'room_url' => $roomUrl,
            'join_url' => $roomUrl,
            'roomUrl' => $roomUrl,
            'joinUrl' => $roomUrl,

            'consultation_id' => $this->consultation_id,

            'schedule' => [
                'date' => optional($this->scheduled_date)->toDateString(),
                'time' => $this->scheduled_time,
                'estimated_duration_minutes' => $this->estimated_duration_minutes,
            ],

            'started_at' => optional($this->started_at)->toIso8601String(),
            'ended_at' => optional($this->ended_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'actual_duration_minutes' => $this->actual_duration_minutes,
            'cancellation_reason' => $this->cancellation_reason,

            'request' => $telemedicineRequest ? [
                'id' => $telemedicineRequest->id,
                'resident_profile_id' => $telemedicineRequest->resident_profile_id,
                'requested_by' => $telemedicineRequest->requested_by,
                'rhu_id' => $telemedicineRequest->rhu_id,
                'chief_complaint' => $telemedicineRequest->chief_complaint,
                'urgency_level' => $telemedicineRequest->urgency_level,
                'symptoms' => $telemedicineRequest->symptoms,
                'additional_notes' => $telemedicineRequest->additional_notes,
                'status' => $telemedicineRequest->status,

                'resident' => $residentProfile ? [
                    'id' => $residentProfile->id,
                    'user_id' => $residentProfile->user_id,
                    'name' => $residentName,
                    'barangay' => $residentProfile->barangay?->name,
                ] : null,

                'rhu' => $telemedicineRequest->rhu ? [
                    'id' => $telemedicineRequest->rhu->barangay_id
                        ?? $telemedicineRequest->rhu->id
                        ?? null,
                    'barangay_id' => $telemedicineRequest->rhu->barangay_id ?? null,
                    'name' => $telemedicineRequest->rhu->name ?? null,
                ] : null,
            ] : null,

            'assigned_doctor' => $assignedDoctor ? [
                'id' => $assignedDoctor->user_id,
                'user_id' => $assignedDoctor->user_id,
                'first_name' => $assignedDoctor->first_name,
                'last_name' => $assignedDoctor->last_name,
                'name' => trim(
                    ($assignedDoctor->first_name ?? '') . ' ' .
                    ($assignedDoctor->last_name ?? '')
                ) ?: ($assignedDoctor->name ?? null),
                'email' => $assignedDoctor->email,
            ] : null,

            'bhw_companion' => $bhwCompanion ? [
                'id' => $bhwCompanion->user_id,
                'user_id' => $bhwCompanion->user_id,
                'first_name' => $bhwCompanion->first_name,
                'last_name' => $bhwCompanion->last_name,
                'name' => trim(
                    ($bhwCompanion->first_name ?? '') . ' ' .
                    ($bhwCompanion->last_name ?? '')
                ) ?: ($bhwCompanion->name ?? null),
                'email' => $bhwCompanion->email,
            ] : null,

            'notes' => $notes ? [
                'id' => $notes->id,
                'session_id' => $notes->session_id,
                'recorded_by' => $notes->recorded_by,
                'subjective' => $notes->subjective,
                'objective' => $notes->objective,
                'assessment' => $notes->assessment,
                'plan' => $notes->plan,
                'primary_diagnosis_code' => $notes->primary_diagnosis_code,
                'primary_diagnosis_label' => $notes->primary_diagnosis_label,
                'medications' => $notes->medications,
                'is_finalized' => (bool) $notes->is_finalized,
                'finalized_at' => optional($notes->finalized_at)->toIso8601String(),
                'created_at' => optional($notes->created_at)->toIso8601String(),
                'updated_at' => optional($notes->updated_at)->toIso8601String(),
            ] : null,

            'referrals' => $referrals->map(function ($referral) {
                return [
                    'id' => $referral->id,
                    'session_id' => $referral->session_id,
                    'issued_by' => $referral->issued_by,
                    'resident_profile_id' => $referral->resident_profile_id,
                    'referral_type' => $referral->referral_type,
                    'referred_to' => $referral->referred_to,
                    'reason' => $referral->reason,
                    'instructions' => $referral->instructions,
                    'follow_up_date' => optional($referral->follow_up_date)->toDateString(),
                    'is_urgent' => (bool) $referral->is_urgent,
                    'status' => $referral->status,
                    'created_at' => optional($referral->created_at)->toIso8601String(),
                    'updated_at' => optional($referral->updated_at)->toIso8601String(),
                ];
            })->values(),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}