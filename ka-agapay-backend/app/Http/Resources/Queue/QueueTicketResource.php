<?php
// app/Http/Resources/Queue/QueueTicketResource.php

namespace App\Http\Resources\Queue;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

class QueueTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resident = $this->resource->relationLoaded('residentProfile')
            ? $this->residentProfile
            : null;

        $rhu = $this->resource->relationLoaded('rhu')
            ? $this->rhu
            : null;

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'queue_number' => $this->ticket_number,

            'patient_name' => $this->residentName($resident),
            'resident_name' => $this->residentName($resident),
            'patient_mobile' => $this->residentMobile($resident),
            'resident_mobile' => $this->residentMobile($resident),
            'barangay' => $resident?->barangay?->barangay_name
                ?? $resident?->barangay?->name
                ?? null,
            'barangay_id' => $resident?->barangay_id ?? null,

            'rhu_id' => $this->rhu_id,
            'rhu_name' => $rhu?->name ?? $rhu?->barangay_name ?? null,

            'service_type' => $this->service_type,
            'service_label' => $this->serviceLabel((string) $this->service_type),

            'queue_type' => $this->queue_type ?? null,
            'source' => $this->source ?? 'walk_in',
            'appointment_id' => $this->appointment_id,
            'consultation_id' => $this->consultation_id,
            'status' => $this->status,

            'priority_score' => (int) ($this->priority_score ?? 0),
            'priority_category' => $this->priority_category ?? 'regular',
            'priority_level' => $this->priorityLevel(),

            'queue_position' => $this->queue_position,
            'call_attempt' => (int) ($this->call_attempt ?? 0),

            'is_senior' => (bool) $this->is_senior,
            'is_pregnant' => (bool) $this->is_pregnant,
            'is_pwd' => (bool) $this->is_pwd,
            'is_pediatric' => (bool) $this->is_pediatric,
            'is_emergency' => (bool) $this->is_emergency,
            'is_bhw_endorsed' => (bool) $this->is_bhw_endorsed,

            'issued_at' => $this->issued_at?->toIso8601String(),
            'called_at' => $this->called_at?->toIso8601String(),
            'service_started_at' => $this->service_started_at?->toIso8601String(),
            'service_ended_at' => $this->service_ended_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'wait_time_minutes' => $this->wait_time_minutes,
            'current_wait_minutes' => $this->currentWaitMinutes(),
            'service_time_minutes' => $this->service_time_minutes,

            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,

            'priority' => [
                'score' => (int) ($this->priority_score ?? 0),
                'category' => $this->priority_category ?? 'regular',
                'level' => $this->priorityLevel(),
                'flags' => [
                    'is_emergency' => (bool) $this->is_emergency,
                    'is_pregnant' => (bool) $this->is_pregnant,
                    'is_senior' => (bool) $this->is_senior,
                    'is_pwd' => (bool) $this->is_pwd,
                    'is_pediatric' => (bool) $this->is_pediatric,
                    'is_bhw_endorsed' => (bool) $this->is_bhw_endorsed,
                ],
            ],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'notification_result' => $this->resource->getAttribute('notification_result'),
        ];
    }

    private function residentName($resident): ?string
    {
        if (!$resident) {
            return null;
        }

        $direct =
            $resident->full_name
            ?? $resident->name
            ?? $resident->resident_name
            ?? null;

        if ($direct) {
            return trim((string) $direct);
        }

        $parts = array_filter([
            $resident->first_name ?? null,
            $resident->middle_name ?? null,
            $resident->last_name ?? null,
        ]);

        $name = trim(implode(' ', $parts));

        return $name !== '' ? $name : null;
    }

    private function residentMobile($resident): ?string
    {
        if (!$resident) {
            return null;
        }

        $mobile =
            $resident->mobile_number
            ?? $resident->contact_number
            ?? $resident->phone_number
            ?? $resident->user?->mobile_number
            ?? null;

        $mobile = trim((string) $mobile);

        return $mobile !== '' ? $mobile : null;
    }

    private function serviceLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'opd_consultation' => 'OPD Consultation',
            'prenatal_checkup' => 'Prenatal Checkup',
            'immunization' => 'Immunization',
            'family_planning' => 'Family Planning',
            'tb_dots' => 'TB DOTS',
            'laboratory' => 'Laboratory',
            'dental' => 'Dental',
            'emergency' => 'Emergency',
            'medicine_release' => 'Medicine Release',
            'bhw_assisted' => 'BHW Assisted',
            default => ucwords(str_replace(['_', '-'], ' ', $serviceType)),
        };
    }

    private function priorityLevel(): string
    {
        $score = (int) ($this->priority_score ?? 0);

        if ((bool) $this->is_emergency || $score >= 80) {
            return 'Critical';
        }

        if ($score >= 60) {
            return 'High';
        }

        if ($score >= 35) {
            return 'Moderate';
        }

        return 'Low';
    }

    private function currentWaitMinutes(): int
    {
        if (!in_array((string) $this->status, ['waiting', 'called', 'in_service'], true)) {
            return (int) ($this->wait_time_minutes ?? 0);
        }

        if (!$this->issued_at) {
            return 0;
        }

        try {
            return max(0, $this->issued_at->diffInMinutes(now()));
        } catch (Throwable) {
            return (int) ($this->wait_time_minutes ?? 0);
        }
    }
}
