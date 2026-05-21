<?php
// app/Services/Referral/ReferralService.php

namespace App\Services\Referral;

use App\Models\Referral;
use App\Models\ReferralUpdate;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReferralService
{
    public function __construct(
        private readonly AuditService $audit
    ) {}

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(array $data): Referral
    {
        return DB::transaction(function () use ($data) {
            $isUrgent = ($data['urgency'] ?? 'routine') !== 'routine';

            $referral = Referral::create([
                'referable_type'          => $this->resolveReferableType($data['referable_type']),
                'referable_id'            => $data['referable_id'],
                'resident_profile_id'     => $data['resident_profile_id'],
                'issued_by'               => Auth::id(),
                'assigned_bhw_id'         => $data['assigned_bhw_id'] ?? null,
                'referral_type'           => $data['referral_type'],
                'referred_facility'       => $data['referred_facility'] ?? null,
                'referred_department'     => $data['referred_department'] ?? null,
                'referred_physician'      => $data['referred_physician'] ?? null,
                'reason'                  => $data['reason'],
                'clinical_summary'        => $data['clinical_summary'] ?? null,
                'instructions'            => $data['instructions'] ?? null,
                'urgency'                 => $data['urgency'] ?? 'routine',
                'follow_up_date'          => $data['follow_up_date'] ?? null,
                'follow_up_time'          => $data['follow_up_time'] ?? null,
                'requires_bhw_monitoring' => $data['requires_bhw_monitoring'] ?? false,
                'is_urgent'               => $isUrgent,
                'status'                  => 'pending',
            ]);

            ReferralUpdate::create([
                'referral_id' => $referral->id,
                'updated_by'  => Auth::id(),
                'update_type' => 'created',
                'to_status'   => 'pending',
                'notes'       => 'Referral created.',
                'metadata'    => [
                    'urgency'      => $referral->urgency,
                    'referral_type'=> $referral->referral_type,
                ],
            ]);

            $this->audit->info(AuditActions::REFERRAL_CREATED, 'referral', [
                'subject'       => $referral,
                'subject_label' => $referral->getAuditLabel(),
                'new_values'    => [
                    'referral_type'       => $referral->referral_type,
                    'urgency'             => $referral->urgency,
                    'resident_profile_id' => $referral->resident_profile_id,
                    'assigned_bhw_id'     => $referral->assigned_bhw_id,
                ],
            ]);

            return $referral->fresh([
                'residentProfile.user',
                'issuedBy',
                'assignedBhw',
                'updates',
            ]);
        });
    }

    // ── Transition status ─────────────────────────────────────────────────────

    public function transition(Referral $referral, string $newStatus, array $data = []): Referral
    {
        if (!$referral->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition referral from [{$referral->status}] to [{$newStatus}]."
            );
        }

        return DB::transaction(function () use ($referral, $newStatus, $data) {
            $fromStatus = $referral->status;
            $updates    = ['status' => $newStatus];

            match ($newStatus) {
                'acknowledged' => $updates += [
                    'acknowledged_by' => Auth::id(),
                    'acknowledged_at' => now(),
                ],
                'completed' => $updates += [
                    'completed_at'  => now(),
                    'outcome_notes' => $data['outcome_notes'] ?? null,
                ],
                'cancelled' => $updates += [
                    'cancelled_at'        => now(),
                    'cancellation_reason' => $data['cancellation_reason'] ?? 'Cancelled.',
                ],
                default => null,
            };

            $referral->update($updates);

            ReferralUpdate::create([
                'referral_id' => $referral->id,
                'updated_by'  => Auth::id(),
                'update_type' => 'status_change',
                'from_status' => $fromStatus,
                'to_status'   => $newStatus,
                'notes'       => $data['notes'] ?? null,
            ]);

            $action = match ($newStatus) {
                'acknowledged' => AuditActions::REFERRAL_ACKNOWLEDGED,
                'completed'    => AuditActions::REFERRAL_COMPLETED,
                default        => 'referral.' . $newStatus,
            };

            $severity = $newStatus === 'cancelled' ? 'warning' : 'info';

            $this->audit->log($action, 'referral', [
                'severity'      => $severity,
                'subject'       => $referral,
                'subject_label' => $referral->getAuditLabel(),
                'old_values'    => ['status' => $fromStatus],
                'new_values'    => ['status' => $newStatus],
            ]);

            return $referral->fresh(['updates.updatedBy', 'acknowledgedBy']);
        });
    }

    // ── BHW submits a monitoring report ──────────────────────────────────────

    public function addBhwReport(Referral $referral, string $notes, array $metadata = []): ReferralUpdate
    {
        abort_unless(
            $referral->assigned_bhw_id === Auth::id() ||
            Auth::user()->hasAnyRole(['mho', 'super_admin']),
            403,
            'You are not assigned to this referral.'
        );

        $update = ReferralUpdate::create([
            'referral_id' => $referral->id,
            'updated_by'  => Auth::id(),
            'update_type' => 'bhw_report',
            'from_status' => $referral->status,
            'to_status'   => $referral->status,
            'notes'       => $notes,
            'metadata'    => $metadata,
        ]);

        $this->audit->info('referral.bhw_report_submitted', 'referral', [
            'subject'       => $referral,
            'subject_label' => $referral->getAuditLabel(),
        ]);

        return $update->fresh(['updatedBy']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolveReferableType(string $shortType): string
    {
        return match ($shortType) {
            'consultation'          => \App\Models\Consultation::class,
            'telemedicine_session'  => \App\Models\TelemedicineSession::class,
            'bhw_assessment'        => 'App\Models\BhwAssessment', // future module
            default                 => $shortType,
        };
    }
}
