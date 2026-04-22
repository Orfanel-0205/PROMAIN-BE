<?php
// app/Services/Telemedicine/TelemedicineService.php

namespace App\Services\Telemedicine;

use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\TelemedicineSessionNote;
use App\Models\TelemedicineReferral;
use App\Models\TelemedicineLog;
use App\Models\Consultation;
use App\Models\MedicalReport;
use App\Models\ResidentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditActions;

class TelemedicineService
{
    public function __construct(
        private readonly AuditService $audit
    ) {}

    /**
     * Resident or BHW submits a telemedicine request.
     */
    public function createRequest(array $data): TelemedicineRequest
    {
        return DB::transaction(function () use ($data) {
            $user = Auth::user();

            $request = TelemedicineRequest::create([
                'resident_profile_id' => $data['resident_profile_id'],
                'requested_by'        => $user->user_id,
                'rhu_id'              => $data['rhu_id'],
                'queue_ticket_id'     => $data['queue_ticket_id'] ?? null,
                'appointment_id'      => $data['appointment_id'] ?? null,
                'chief_complaint'     => $data['chief_complaint'],
                'urgency_level'       => $data['urgency_level'] ?? 'routine',
                'symptoms'            => $data['symptoms'] ?? null,
                'additional_notes'    => $data['additional_notes'] ?? null,
                'is_bhw_assisted'     => $data['is_bhw_assisted'] ?? false,
                'bhw_notes'           => $data['bhw_notes'] ?? null,
                'endorsed_by_bhw'     => $user->hasRole('bhw') ? $user->user_id : null,
                'status'              => 'pending',
            ]);

            $this->writeLog($request, null, 'pending', 'request_created', [
                'urgency_level'  => $request->urgency_level,
                'is_bhw_assisted'=> $request->is_bhw_assisted,
            ]);

            $this->audit->info(AuditActions::TELE_REQUEST_SUBMITTED, 'telemedicine', [
                'subject'       => $request,
                'subject_label' => "Telemedicine Request #{$request->id}",
                'new_values'    => ['status' => 'pending'],
            ]);

            return $request->fresh(['residentProfile', 'requestedBy', 'rhu']);
        });
    }

    /**
     * Staff screens a pending request — approve (screen) or reject.
     */
    public function screenRequest(TelemedicineRequest $request, array $data): TelemedicineRequest
    {
        if (!$request->canTransitionTo($data['decision'] === 'approve' ? 'screened' : 'rejected')) {
            throw new \DomainException("Request [{$request->id}] cannot be screened from status [{$request->status}].");
        }

        return DB::transaction(function () use ($request, $data) {
            $fromStatus = $request->status;

            if ($data['decision'] === 'approve') {
                $request->update([
                    'status'          => 'screened',
                    'screened_by'     => Auth::id(),
                    'screening_notes' => $data['screening_notes'] ?? null,
                    'screened_at'     => now(),
                ]);

                $this->writeLog($request, $fromStatus, 'screened', 'request_screened', [
                    'screening_notes' => $data['screening_notes'] ?? null,
                ]);

                // If staff wants to schedule immediately during screening
                if (!empty($data['schedule_now']) && $data['schedule_now']) {
                    $this->createSession($request, [
                        'assigned_doctor_id'          => $data['assigned_doctor_id'],
                        'scheduled_date'              => $data['scheduled_date'],
                        'scheduled_time'              => $data['scheduled_time'],
                        'session_mode'                => $data['session_mode'] ?? 'in_app',
                        'estimated_duration_minutes'  => 15,
                    ]);
                }
            } else {
                $request->update([
                    'status'           => 'rejected',
                    'screened_by'      => Auth::id(),
                    'screening_notes'  => $data['screening_notes'] ?? null,
                    'screened_at'      => now(),
                    'rejection_reason' => $data['rejection_reason'] ?? 'Request did not meet triage criteria.',
                ]);

                $this->writeLog($request, $fromStatus, 'rejected', 'request_rejected', [
                    'rejection_reason' => $request->rejection_reason,
                ]);
            }

            $action = $data['decision'] === 'approve' ? AuditActions::TELE_REQUEST_SCREENED : AuditActions::TELE_REQUEST_REJECTED;
            $this->audit->info($action, 'telemedicine', [
                'subject'       => $request,
                'subject_label' => "Telemedicine Request #{$request->id}",
                'old_values'    => ['status' => $fromStatus],
                'new_values'    => ['status' => $request->status],
            ]);

            return $request->fresh(['residentProfile', 'screenedBy', 'session']);
        });
    }

    /**
     * Create and schedule a session for a screened request.
     */
    public function createSession(TelemedicineRequest $request, array $data): TelemedicineSession
    {
        if ($request->status !== 'screened') {
            throw new \DomainException("A session can only be created for a screened request.");
        }

        if ($request->session) {
            throw new \DomainException("A session already exists for request [{$request->id}].");
        }

        return DB::transaction(function () use ($request, $data) {
            $session = TelemedicineSession::create([
                'request_id'                 => $request->id,
                'assigned_doctor_id'         => $data['assigned_doctor_id'],
                'bhw_companion_id'           => $data['bhw_companion_id'] ?? null,
                'scheduled_date'             => $data['scheduled_date'],
                'scheduled_time'             => $data['scheduled_time'],
                'estimated_duration_minutes' => $data['estimated_duration_minutes'] ?? 15,
                'session_mode'               => $data['session_mode'] ?? 'in_app',
                'session_token'              => Str::random(40),
                'status'                     => 'scheduled',
            ]);

            $request->update(['status' => 'scheduled']);

            $this->writeLog($request, 'screened', 'scheduled', 'session_created', [
                'session_id'         => $session->id,
                'assigned_doctor_id' => $session->assigned_doctor_id,
                'scheduled_date'     => $session->scheduled_date->toDateString(),
                'scheduled_time'     => $data['scheduled_time'],
            ]);

            $this->writeLog($session, null, 'scheduled', 'session_scheduled', [
                'assigned_doctor_id' => $session->assigned_doctor_id,
            ]);

            $this->audit->info(AuditActions::TELE_SESSION_CREATED, 'telemedicine', [
                'subject'       => $session,
                'subject_label' => "Telemedicine Session #{$session->id}",
                'new_values'    => ['status' => 'scheduled'],
            ]);

            return $session->fresh(['request.residentProfile', 'assignedDoctor']);
        });
    }

    /**
     * Transition a session to a new status (start, pause, end, no-show, cancel).
     */
    public function transitionSessionStatus(TelemedicineSession $session, string $newStatus, array $data = []): TelemedicineSession
    {
        if (!$session->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition session [{$session->id}] from [{$session->status}] to [{$newStatus}]."
            );
        }

        return DB::transaction(function () use ($session, $newStatus, $data) {
            $fromStatus = $session->status;
            $now = now();
            $updates = ['status' => $newStatus];

            switch ($newStatus) {
                case 'active':
                    if (!$session->started_at) {
                        $updates['started_at'] = $now;
                    }
                    break;

                case 'ended':
                    $updates['ended_at'] = $now;
                    if ($session->started_at) {
                        $updates['actual_duration_minutes'] = (int) $now->diffInMinutes($session->started_at);
                    }
                    // Automatically create the linked consultation record
                    $consultation = $this->createConsultationFromSession($session);
                    $updates['consultation_id'] = $consultation->id;
                    // Mark the parent request as completed
                    $session->request->update(['status' => 'completed']);
                    $this->writeLog($session->request, 'scheduled', 'completed', 'request_completed', [
                        'session_id'      => $session->id,
                        'consultation_id' => $consultation->id,
                    ]);
                    break;

                case 'cancelled':
                    $updates['cancelled_at']        = $now;
                    $updates['cancellation_reason'] = $data['cancellation_reason'] ?? 'No reason provided.';
                    break;

                case 'no_show':
                    $updates['cancelled_at'] = $now;
                    break;
            }

            $session->update($updates);

            $this->writeLog($session, $fromStatus, $newStatus, "session_{$newStatus}", array_merge($data, [
                'performed_by' => Auth::id(),
            ]));

            $actionMap = [
                'active' => AuditActions::TELE_SESSION_STARTED,
                'ended'  => AuditActions::TELE_SESSION_ENDED,
            ];
            $action = $actionMap[$newStatus] ?? 'telemedicine_session.updated';

            $this->audit->info($action, 'telemedicine', [
                'subject'       => $session,
                'subject_label' => "Telemedicine Session #{$session->id}",
                'old_values'    => ['status' => $fromStatus],
                'new_values'    => ['status' => $newStatus],
            ]);

            return $session->fresh(['request.residentProfile', 'assignedDoctor', 'notes', 'referrals']);
        });
    }

    /**
     * Doctor saves (or updates) SOAP notes for a session.
     */
    public function saveNotes(TelemedicineSession $session, array $data): TelemedicineSessionNote
    {
        if (!in_array($session->status, ['active', 'paused', 'ended'])) {
            throw new \DomainException("Notes can only be saved for active, paused, or ended sessions.");
        }

        return DB::transaction(function () use ($session, $data) {
            $isFinalized = !empty($data['finalize']) && $data['finalize'];

            $notes = TelemedicineSessionNote::updateOrCreate(
                ['session_id' => $session->id],
                [
                    'recorded_by'              => Auth::id(),
                    'subjective'               => $data['subjective'] ?? null,
                    'objective'                => $data['objective'] ?? null,
                    'assessment'               => $data['assessment'] ?? null,
                    'plan'                     => $data['plan'] ?? null,
                    'primary_diagnosis_code'   => $data['primary_diagnosis_code'] ?? null,
                    'primary_diagnosis_label'  => $data['primary_diagnosis_label'] ?? null,
                    'medications'              => $data['medications'] ?? null,
                    'is_finalized'             => $isFinalized,
                    'finalized_at'             => $isFinalized ? now() : null,
                ]
            );

            // If finalizing, sync data into the linked consultation record
            if ($isFinalized && $session->consultation_id) {
                Consultation::find($session->consultation_id)?->update([
                    'chief_complaint' => $session->request->chief_complaint,
                    'diagnosis'       => $data['primary_diagnosis_label'] ?? $data['assessment'] ?? null,
                    'treatment'       => $data['plan'] ?? null,
                    'status'          => 'completed',
                ]);

                // Auto-generate a medical report
                MedicalReport::create([
                    'user_id'         => $session->request->residentProfile->user_id,
                    'consultation_id' => $session->consultation_id,
                    'created_by'      => Auth::id(),
                    'report_type'     => 'telemedicine_summary',
                    'findings'        => $data['assessment'] ?? null,
                    'recommendations' => $data['plan'] ?? null,
                ]);
            }

            $this->writeLog($session, $session->status, $session->status,
                $isFinalized ? 'notes_finalized' : 'notes_saved', [
                    'primary_diagnosis_code' => $data['primary_diagnosis_code'] ?? null,
                    'finalized'              => $isFinalized,
                ]);

            if ($isFinalized) {
                $this->audit->info(AuditActions::TELE_NOTES_FINALIZED, 'telemedicine', [
                    'subject'       => $notes,
                    'subject_label' => "Session Notes #{$notes->id}",
                    'new_values'    => ['is_finalized' => true],
                ]);
            }

            return $notes->fresh(['recordedBy']);
        });
    }

    /**
     * Doctor issues a referral after or during a session.
     */
    public function createReferral(TelemedicineSession $session, array $data): TelemedicineReferral
    {
        if ($session->isTerminal() && $session->status !== 'ended') {
            throw new \DomainException("Referrals can only be created for ended or active sessions.");
        }

        return DB::transaction(function () use ($session, $data) {
            $referral = TelemedicineReferral::create([
                'session_id'          => $session->id,
                'issued_by'           => Auth::id(),
                'resident_profile_id' => $session->request->resident_profile_id,
                'referral_type'       => $data['referral_type'],
                'referred_to'         => $data['referred_to'] ?? null,
                'reason'              => $data['reason'],
                'instructions'        => $data['instructions'] ?? null,
                'follow_up_date'      => $data['follow_up_date'] ?? null,
                'is_urgent'           => $data['is_urgent'] ?? false,
                'status'              => 'pending',
            ]);

            $this->writeLog($session, $session->status, $session->status, 'referral_issued', [
                'referral_id'   => $referral->id,
                'referral_type' => $referral->referral_type,
                'is_urgent'     => $referral->is_urgent,
            ]);

            $this->audit->info(AuditActions::TELE_REFERRAL_ISSUED, 'telemedicine', [
                'subject'       => $referral,
                'subject_label' => "Referral #{$referral->id}",
                'new_values'    => ['status' => 'pending'],
            ]);

            return $referral->fresh(['issuedBy', 'residentProfile']);
        });
    }

    /**
     * Get daily telemedicine summary for admin dashboard.
     */
    public function getDailySummary(int $rhuId, ?string $date = null): array
    {
        $date = $date ? Carbon::parse($date) : today();

        $requests = TelemedicineRequest::forRhu($rhuId)
            ->whereDate('created_at', $date)
            ->get();

        $sessions = TelemedicineSession::whereHas('request', fn($q) => $q->where('rhu_id', $rhuId))
            ->whereDate('scheduled_date', $date)
            ->get();

        return [
            'date'                       => $date->toDateString(),
            'requests_total'             => $requests->count(),
            'requests_pending'           => $requests->where('status', 'pending')->count(),
            'requests_screened'          => $requests->where('status', 'screened')->count(),
            'requests_scheduled'         => $requests->where('status', 'scheduled')->count(),
            'requests_completed'         => $requests->where('status', 'completed')->count(),
            'requests_rejected'          => $requests->where('status', 'rejected')->count(),
            'requests_cancelled'         => $requests->where('status', 'cancelled')->count(),
            'urgent_requests'            => $requests->whereIn('urgency_level', ['urgent', 'emergency'])->count(),
            'bhw_assisted_requests'      => $requests->where('is_bhw_assisted', true)->count(),
            'sessions_scheduled'         => $sessions->where('status', 'scheduled')->count(),
            'sessions_completed'         => $sessions->where('status', 'ended')->count(),
            'sessions_no_show'           => $sessions->where('status', 'no_show')->count(),
            'avg_session_duration_mins'  => round(
                $sessions->where('status', 'ended')->avg('actual_duration_minutes') ?? 0, 1
            ),
            'by_urgency' => $requests->groupBy('urgency_level')
                ->map(fn($g) => $g->count())->toArray(),
        ];
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    /**
     * Create a Consultation record from a telemedicine session ending.
     */
    private function createConsultationFromSession(TelemedicineSession $session): Consultation
    {
        $request = $session->request;
        $residentUserId = $request->residentProfile->user_id;

        return Consultation::create([
            'user_id'           => $residentUserId,
            'attended_by'       => $session->assigned_doctor_id,
            'consultation_date' => now()->toDateString(),
            'chief_complaint'   => $request->chief_complaint,
            'diagnosis'         => null, // to be filled when notes are finalized
            'treatment'         => null,
            'status'            => 'open',
        ]);
    }

    /**
     * Write a polymorphic audit log entry.
     */
    private function writeLog(
        TelemedicineRequest|TelemedicineSession $model,
        ?string $from,
        string $to,
        string $action,
        array $metadata = []
    ): void {
        TelemedicineLog::create([
            'loggable_type' => get_class($model),
            'loggable_id'   => $model->id,
            'performed_by'  => Auth::id(),
            'action'        => $action,
            'from_status'   => $from,
            'to_status'     => $to,
            'metadata'      => array_merge($metadata, [
                'ip'         => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]),
            'performed_at'  => now(),
        ]);
    }
}