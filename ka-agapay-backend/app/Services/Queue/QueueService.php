<?php
// app/Services/Queue/QueueService.php

namespace App\Services\Queue;

use App\Models\Appointment;
use App\Models\Barangay;
use App\Models\Consultation;
use App\Models\QueueCounter;
use App\Models\QueueLog;
use App\Models\QueuePriorityScore;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Services\Audit\AuditActions;
use App\Services\Audit\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class QueueService
{
    private const ACTIVE_STATUSES = ['waiting', 'called', 'in_service'];
    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'no_show'];

    private const SERVICE_CODES = [
        'opd_consultation' => 'OPD',
        'prenatal_checkup' => 'PRE',
        'immunization' => 'IMM',
        'family_planning' => 'FP',
        'tb_dots' => 'TB',
        'laboratory' => 'LAB',
        'dental' => 'DEN',
        'emergency' => 'ER',
        'medicine_release' => 'MED',
        'bhw_assisted' => 'BHW',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly QueuePrioritizationService $prioritizationService
    ) {
    }

    /**
     * Issue a queue ticket.
     *
     * Real-life protection:
     * - Prevents duplicate active tickets for the same resident/service/day.
     * - Uses transaction + row lock for counter safety.
     * - Recalculates positions after every ticket issue.
     * - Keeps AI/priority scoring explainable, but staff still controls final action.
     */
    public function issueTicket(array $data): QueueTicket
    {
        return DB::transaction(function () use ($data) {
            $residentProfile = ResidentProfile::findOrFail((int) $data['resident_profile_id']);

            $rhuId = (int) $data['rhu_id'];
            $serviceType = (string) $data['service_type'];

            $existing = QueueTicket::query()
                ->forRhu($rhuId)
                ->byServiceType($serviceType)
                ->forToday()
                ->where('resident_profile_id', $residentProfile->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
            }

            $flags = $this->resolvePatientFlags($residentProfile, $data);

            $counter = $this->incrementAndGetCounter($rhuId, $serviceType);

            $ticketNumber = $this->formatTicketNumber(
                $rhuId,
                $serviceType,
                (int) $counter->last_issued_number
            );

            $priorityResult = $this->computePrioritySafely($residentProfile, $data, $flags);

            $ticketPriorityCategory = $this->resolveTicketPriorityCategory(
                $flags,
                (int) $priorityResult['priority_score']
            );

            $createPayload = [
                'ticket_number' => $ticketNumber,
                'resident_profile_id' => $residentProfile->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'rhu_id' => $rhuId,
                'issued_by' => Auth::id(),
                'served_by' => null,

                'service_type' => $serviceType,

                'priority_score' => (int) $priorityResult['priority_score'],
                'priority_category' => $ticketPriorityCategory,

                'is_senior' => $flags['is_senior'],
                'is_pregnant' => $flags['is_pregnant'],
                'is_pwd' => $flags['is_pwd'],
                'is_pediatric' => $flags['is_pediatric'],
                'is_emergency' => $flags['is_emergency'],
                'is_bhw_endorsed' => $flags['is_bhw_endorsed'],

                'status' => 'waiting',
                'queue_position' => 0,
                'call_attempt' => 0,
                'issued_at' => now(),
                'notes' => $data['notes'] ?? null,
            ];

            if (Schema::hasColumn('queue_tickets', 'queue_type')) {
                $createPayload['queue_type'] = $priorityResult['queue_type'] ?? 'walk_in';
            }

            if (Schema::hasColumn('queue_tickets', 'source')) {
                $createPayload['source'] = $data['source'] ?? 'walk_in';
            }

            $ticket = QueueTicket::create($createPayload);

            $this->storePriorityScoreSafely(
                $ticket,
                $residentProfile,
                $priorityResult,
                $data
            );

            $this->reflowQueuePositions($rhuId, $serviceType);

            $this->writeLog($ticket, null, 'waiting', 'issued', [
                'priority_score' => (int) $priorityResult['priority_score'],
                'priority_category' => $ticketPriorityCategory,
                'queue_type' => $priorityResult['queue_type'] ?? null,
            ]);

            $this->auditInfoSafely(
                AuditActions::QUEUE_TICKET_ISSUED,
                $ticket,
                [
                    'status' => 'waiting',
                    'priority_score' => (int) $priorityResult['priority_score'],
                    'priority_category' => $ticketPriorityCategory,
                ]
            );

            return $ticket->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
        });
    }

    /**
     * Sync an approved appointment into the RHU queue.
     *
     * Called when an admin approves/schedules/confirms an appointment.
     * - Routes strictly to appointment.rhu_id (RHU 1 vs RHU 2).
     * - Prevents duplicate tickets for the same appointment_id.
     * - If a ticket already exists for this appointment, it is updated (RHU/source).
     * - Otherwise a new ticket is issued via issueTicket() (counter, number,
     *   priority, dedup all handled there) and linked back to the appointment.
     *
     * Returns the queue ticket, or null if it could not be synced (caller should
     * treat a null as non-fatal — approval itself must still succeed).
     */
    public function syncAppointmentToQueue(Appointment $appointment): ?QueueTicket
    {
        if (!Schema::hasTable('queue_tickets') || empty($appointment->user_id)) {
            return null;
        }

        $resident = $this->resolveResidentProfileForAppointment($appointment);

        if (!$resident) {
            return null;
        }

        $rhuId = $this->resolveAppointmentRhuId($appointment, $resident);

        if (!$rhuId) {
            return null;
        }

        $serviceType = $this->resolveAppointmentServiceType($appointment);

        // 1) Already linked to a ticket? Update it instead of creating a duplicate.
        $existing = QueueTicket::query()
            ->where('appointment_id', $appointment->id)
            ->latest('id')
            ->first();

        if ($existing) {
            if (!$existing->isTerminal()) {
                $updates = ['rhu_id' => $rhuId];

                if (Schema::hasColumn('queue_tickets', 'source') && empty($existing->source)) {
                    $updates['source'] = 'online_appointment';
                }

                $existing->update($updates);
            }

            return $existing->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
        }

        // 2) No ticket yet — issue one tied to this appointment.
        $ticket = $this->issueTicket([
            'resident_profile_id' => $resident->id,
            'rhu_id' => $rhuId,
            'service_type' => $serviceType,
            'appointment_id' => $appointment->id,
            'source' => 'online_appointment',
        ]);

        // issueTicket() dedups by resident/service/day, so it may have returned a
        // pre-existing walk-in ticket without an appointment link — backfill it.
        $needsLink = empty($ticket->appointment_id)
            || (Schema::hasColumn('queue_tickets', 'source') && empty($ticket->source));

        if ($needsLink && !$ticket->isTerminal()) {
            $link = ['appointment_id' => $appointment->id];

            if (Schema::hasColumn('queue_tickets', 'source')) {
                $link['source'] = 'online_appointment';
            }

            $ticket->update($link);
        }

        return $ticket->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
    }

    private function resolveResidentProfileForAppointment(Appointment $appointment): ?ResidentProfile
    {
        $resident = ResidentProfile::query()
            ->where('user_id', $appointment->user_id)
            ->first();

        if ($resident) {
            return $resident;
        }

        // The backfill migration normally guarantees a profile exists; create a
        // minimal one as a safety net so approval can still reach the queue.
        try {
            return ResidentProfile::create(['user_id' => $appointment->user_id]);
        } catch (Throwable $e) {
            logger()->warning('[QueueService] Could not create resident profile for appointment queue sync.', [
                'appointment_id' => $appointment->id,
                'user_id' => $appointment->user_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveAppointmentRhuId(Appointment $appointment, ResidentProfile $resident): ?int
    {
        if (Schema::hasColumn('appointments', 'rhu_id') && !empty($appointment->rhu_id)) {
            return (int) $appointment->rhu_id;
        }

        if (!empty($resident->barangay_id)) {
            return (int) $resident->barangay_id;
        }

        $fallback = (int) (Barangay::query()->orderBy('barangay_id')->value('barangay_id') ?? 0);

        return $fallback > 0 ? $fallback : null;
    }

    private function resolveAppointmentServiceType(Appointment $appointment): string
    {
        // Appointments do not carry a queue service_type; default OPD consultation.
        // (Online + onsite both enter the queue as OPD unless extended later.)
        return 'opd_consultation';
    }

    /**
     * PHASE 1 GUARD.
     *
     * An OPD consultation queue ticket may only be completed once its SOAP
     * consultation is completed. This blocks staff from closing the queue and
     * bypassing the medical record from the queue monitor.
     *
     * IMPORTANT: The legitimate completion path is ConsultationController, which
     * completes the consultation first and then updates the ticket via a direct
     * DB write (NOT through transitionStatus), so this guard never blocks the
     * normal SOAP → queue completion sync.
     *
     * @throws ValidationException
     */
    private function assertOpdConsultationCompleted(QueueTicket $ticket): void
    {
        if ((string) $ticket->service_type !== 'opd_consultation') {
            return;
        }

        if (!Schema::hasTable('consultations')) {
            return;
        }

        $consultation = $this->findConsultationForTicket($ticket);

        if (!$consultation) {
            throw ValidationException::withMessages([
                'status' => 'Start the SOAP consultation first before completing this OPD queue ticket.',
            ]);
        }

        if ((string) $consultation->status !== 'completed') {
            throw ValidationException::withMessages([
                'status' => 'Complete the SOAP consultation first before completing this OPD queue ticket.',
            ]);
        }
    }

    private function findConsultationForTicket(QueueTicket $ticket): ?Consultation
    {
        if (
            Schema::hasColumn('queue_tickets', 'consultation_id')
            && !empty($ticket->consultation_id)
        ) {
            $byTicket = Consultation::query()
                ->whereKey($ticket->consultation_id)
                ->first();

            if ($byTicket) {
                return $byTicket;
            }
        }

        // Primary link: the appointment the ticket was issued from.
        if (!empty($ticket->appointment_id)) {
            $byAppointment = Consultation::query()
                ->where('appointment_id', $ticket->appointment_id)
                ->latest('id')
                ->first();

            if ($byAppointment) {
                return $byAppointment;
            }
        }

        // Fallback for walk-in OPD tickets with no appointment link:
        // the resident's most recent consultation today.
        $userId = DB::table('resident_profiles')
            ->where('id', $ticket->resident_profile_id)
            ->value('user_id');

        if (!$userId) {
            return null;
        }

        return Consultation::query()
            ->where('user_id', $userId)
            ->whereDate('consultation_date', today())
            ->latest('id')
            ->first();
    }

    /**
     * Update ticket status safely.
     */
    public function transitionStatus(QueueTicket $ticket, string $newStatus, array $data = []): QueueTicket
    {
        return DB::transaction(function () use ($ticket, $newStatus, $data) {
            $ticket = QueueTicket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($ticket->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "Ticket {$ticket->ticket_number} is already {$ticket->status}.",
                ]);
            }

            if ($ticket->status === $newStatus) {
                return $ticket->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
            }

            if (!$ticket->canTransitionTo($newStatus)) {
                throw ValidationException::withMessages([
                    'status' => "Invalid queue action: {$ticket->status} cannot become {$newStatus}.",
                ]);
            }

            // PHASE 1 GUARD: an OPD queue ticket can only be completed after its
            // SOAP consultation is completed. Prevents bypassing the medical
            // record from the queue monitor.
            if ($newStatus === 'completed') {
                $this->assertOpdConsultationCompleted($ticket);
            }

            $fromStatus = (string) $ticket->status;
            $now = now();

            $updates = [
                'status' => $newStatus,
            ];

            $metadata = [];

            if ($newStatus === 'called') {
                $updates['called_at'] = $now;
                $updates['call_attempt'] = ((int) $ticket->call_attempt) + 1;
                $updates['wait_time_minutes'] = $this->minutesBetween($ticket->issued_at, $now);
                $this->updateCounterCurrentServing($ticket);
            }

            if ($newStatus === 'in_service') {
                $updates['service_started_at'] = $now;
                $updates['served_by'] = Auth::id();

                if (!$ticket->called_at) {
                    $updates['called_at'] = $now;
                    $updates['wait_time_minutes'] = $this->minutesBetween($ticket->issued_at, $now);
                }
            }

            if ($newStatus === 'completed') {
                $updates['service_ended_at'] = $now;

                if (Schema::hasColumn('queue_tickets', 'completed_at')) {
                    $updates['completed_at'] = $now;
                }

                if (!$ticket->service_started_at) {
                    $updates['service_started_at'] = $now;
                }

                $serviceStart = $ticket->service_started_at ?: $now;
                $updates['service_time_minutes'] = max(1, $this->minutesBetween($serviceStart, $now));
            }

            if ($newStatus === 'cancelled') {
                $updates['cancelled_at'] = $now;
                $updates['cancellation_reason'] =
                    $data['cancellation_reason']
                    ?? $data['notes']
                    ?? 'Cancelled by RHU staff.';
            }

            if ($newStatus === 'no_show') {
                $updates['cancelled_at'] = $now;
                $updates['cancellation_reason'] =
                    $data['cancellation_reason']
                    ?? $data['notes']
                    ?? 'Patient did not respond when called.';
            }

            if ($newStatus === 'skipped') {
                $metadata['skip_reason'] = $data['notes'] ?? 'Patient skipped temporarily.';
            }

            if ($newStatus === 'waiting') {
                $updates['called_at'] = null;
                $updates['service_started_at'] = null;
                $metadata['return_reason'] = $data['notes'] ?? 'Returned to waiting queue.';
            }

            $ticket->update($updates);

            $this->writeLog($ticket, $fromStatus, $newStatus, $newStatus, $metadata);

            $this->reflowQueuePositions((int) $ticket->rhu_id, (string) $ticket->service_type);

            return $ticket->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
        });
    }

    /**
     * Move a called ticket into service and guarantee a SOAP consultation exists.
     * Repeated clicks return the same linked consultation instead of creating
     * duplicate records.
     *
     * @return array{ticket: QueueTicket, consultation: Consultation}
     */
    public function startService(QueueTicket $ticket): array
    {
        return DB::transaction(function () use ($ticket) {
            $ticket = QueueTicket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($ticket->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => "Ticket {$ticket->ticket_number} is already {$ticket->status}.",
                ]);
            }

            $fromStatus = (string) $ticket->status;
            $now = now();

            if ($ticket->status === 'called') {
                $updates = [
                    'status' => 'in_service',
                    'service_started_at' => $ticket->service_started_at ?: $now,
                    'served_by' => $ticket->served_by ?: Auth::id(),
                ];

                if (!$ticket->called_at) {
                    $updates['called_at'] = $now;
                    $updates['wait_time_minutes'] = $this->minutesBetween($ticket->issued_at, $now);
                }

                $ticket->update($updates);
                $this->writeLog($ticket, $fromStatus, 'in_service', 'start_service');
            } elseif ($ticket->status !== 'in_service') {
                throw ValidationException::withMessages([
                    'status' => "Start Service is only available for called tickets. Current status: {$ticket->status}.",
                ]);
            }

            $ticket = $ticket->fresh(['residentProfile.barangay', 'appointment', 'rhu', 'issuedBy', 'servedBy']);
            $consultation = $this->ensureConsultationForTicket($ticket);

            if (
                Schema::hasColumn('queue_tickets', 'consultation_id')
                && (int) ($ticket->consultation_id ?? 0) !== (int) $consultation->id
            ) {
                $ticket->update(['consultation_id' => $consultation->id]);
            }

            $this->reflowQueuePositions((int) $ticket->rhu_id, (string) $ticket->service_type);

            return [
                'ticket' => $ticket->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']),
                'consultation' => $consultation->fresh(),
            ];
        });
    }

    private function ensureConsultationForTicket(QueueTicket $ticket): Consultation
    {
        $existing = $this->findConsultationForTicket($ticket);

        if ($existing) {
            return $existing;
        }

        $ticket->loadMissing(['residentProfile', 'appointment']);

        $resident = $ticket->residentProfile;
        $appointment = $ticket->appointment;
        $userId = (int) ($appointment?->user_id ?? $resident?->user_id ?? 0);

        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'consultation' => 'Cannot start SOAP because this queue ticket is not linked to a patient account.',
            ]);
        }

        $sameDayOpen = Consultation::query()
            ->where('user_id', $userId)
            ->whereDate('consultation_date', today())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->latest('id')
            ->first();

        if ($sameDayOpen) {
            return $sameDayOpen;
        }

        $complaint = $this->ticketChiefComplaint($ticket);

        $payload = $this->filterTablePayload('consultations', [
            'appointment_id' => $ticket->appointment_id,
            'user_id' => $userId,
            'attended_by' => Auth::id(),
            'consultation_date' => today()->toDateString(),
            'chief_complaint' => $complaint,
            'subjective' => $complaint,
            'status' => 'open',
            'started_at' => now(),
        ]);

        return Consultation::create($payload);
    }

    private function ticketChiefComplaint(QueueTicket $ticket): ?string
    {
        $appointment = $ticket->appointment;

        $value = trim((string) (
            $appointment?->reason
            ?? $appointment?->symptoms
            ?? $appointment?->purpose
            ?? $ticket->notes
            ?? ''
        ));

        if ($value !== '') {
            return $value;
        }

        return match ((string) $ticket->service_type) {
            'opd_consultation' => 'OPD consultation',
            'prenatal_checkup' => 'Prenatal checkup',
            'immunization' => 'Immunization service',
            'family_planning' => 'Family planning service',
            'tb_dots' => 'TB DOTS service',
            'laboratory' => 'Laboratory service',
            'dental' => 'Dental service',
            'emergency' => 'Emergency service',
            'medicine_release' => 'Medicine release',
            'bhw_assisted' => 'BHW-assisted service',
            default => 'RHU service',
        };
    }

    private function filterTablePayload(string $table, array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, $key) => Schema::hasColumn($table, (string) $key))
            ->all();
    }

    /**
     * Call next patient.
     *
     * Real-life protection:
     * - Anti-starvation is applied before selecting next patient.
     * - If there is already a called patient, the system returns that patient
     *   instead of accidentally calling multiple patients.
     * - Emergency and high-priority patients are ordered first.
     * - Same priority uses FIFO to respect physical arrival.
     */
    public function callNext(int $rhuId, string $serviceType): ?QueueTicket
    {
        return DB::transaction(function () use ($rhuId, $serviceType) {
            $this->applyAntiStarvationSafely($rhuId);
            $this->reflowQueuePositions($rhuId, $serviceType);

            $alreadyCalled = QueueTicket::query()
                ->forRhu($rhuId)
                ->byServiceType($serviceType)
                ->forToday()
                ->where('status', 'called')
                ->orderBy('called_at')
                ->lockForUpdate()
                ->first();

            if ($alreadyCalled) {
                return $alreadyCalled->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
            }

            $next = QueueTicket::query()
                ->forRhu($rhuId)
                ->byServiceType($serviceType)
                ->forToday()
                ->waiting()
                ->orderByDesc('is_emergency')
                ->orderByDesc('priority_score')
                ->orderBy('issued_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$next) {
                return null;
            }

            return $this->transitionStatus($next, 'called', [
                'system_action' => 'call_next',
            ]);
        });
    }

    /**
     * Call the next PRIORITY patient explicitly.
     *
     * Unlike callNext (which is priority-first but will call anyone if no
     * priority case is waiting), this only pulls a genuine priority case:
     * emergency / pregnant / senior / PWD / pediatric / BHW-endorsed, or a
     * high computed score. Returns null when there is no priority patient
     * waiting, so staff get a clear "no priority patients" message instead of
     * accidentally pulling a regular walk-in.
     *
     * Keeps the single-active-call guarantee: if a patient is already called,
     * that ticket is returned instead of calling a second patient.
     */
    public function callPriorityNext(int $rhuId, string $serviceType): ?QueueTicket
    {
        return DB::transaction(function () use ($rhuId, $serviceType) {
            $this->applyAntiStarvationSafely($rhuId);
            $this->reflowQueuePositions($rhuId, $serviceType);

            $alreadyCalled = QueueTicket::query()
                ->forRhu($rhuId)
                ->byServiceType($serviceType)
                ->forToday()
                ->where('status', 'called')
                ->orderBy('called_at')
                ->lockForUpdate()
                ->first();

            if ($alreadyCalled) {
                return $alreadyCalled->fresh(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy']);
            }

            $next = QueueTicket::query()
                ->forRhu($rhuId)
                ->byServiceType($serviceType)
                ->forToday()
                ->waiting()
                ->where(function ($q) {
                    $q->where('is_emergency', true)
                        ->orWhere('is_pregnant', true)
                        ->orWhere('is_senior', true)
                        ->orWhere('is_pwd', true)
                        ->orWhere('is_pediatric', true)
                        ->orWhere('is_bhw_endorsed', true)
                        ->orWhere('priority_score', '>=', 35)
                        ->orWhereNotIn('priority_category', ['', 'regular', 'Low']);
                })
                ->orderByDesc('is_emergency')
                ->orderByDesc('priority_score')
                ->orderBy('issued_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$next) {
                return null;
            }

            return $this->transitionStatus($next, 'called', [
                'system_action' => 'call_priority_next',
            ]);
        });
    }

    /**
     * Live queue for monitor.
     */
    public function getLiveQueue(int $rhuId, ?string $serviceType = null): array
    {
        $this->applyAntiStarvationSafely($rhuId);
        $this->reflowQueuePositions($rhuId, $serviceType);

        $query = QueueTicket::query()
            ->with(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy'])
            ->forRhu($rhuId)
            ->forToday()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByRaw("
                CASE status
                    WHEN 'called' THEN 1
                    WHEN 'in_service' THEN 2
                    WHEN 'waiting' THEN 3
                    ELSE 4
                END
            ")
            ->orderBy('queue_position')
            ->orderByDesc('is_emergency')
            ->orderByDesc('priority_score')
            ->orderBy('issued_at')
            ->orderBy('id');

        if ($serviceType) {
            $query->byServiceType($serviceType);
        }

        $tickets = $query->get();

        return [
            'waiting' => $tickets->where('status', 'waiting')->values(),
            'called' => $tickets->where('status', 'called')->values(),
            'in_service' => $tickets->where('status', 'in_service')->values(),
        ];
    }

    /**
     * Daily summary for UI cards and reports.
     */
    public function getDailySummary(int $rhuId, ?string $date = null): array
    {
        $targetDate = $date ? Carbon::parse($date)->toDateString() : today()->toDateString();

        $tickets = QueueTicket::query()
            ->forRhu($rhuId)
            ->whereDate('issued_at', $targetDate)
            ->get();

        $waiting = $tickets->where('status', 'waiting');
        $completed = $tickets->where('status', 'completed');

        $averageWait = round((float) $tickets
            ->whereNotNull('wait_time_minutes')
            ->avg('wait_time_minutes'), 1);

        $averageService = round((float) $completed
            ->whereNotNull('service_time_minutes')
            ->avg('service_time_minutes'), 1);

        $activeServices = $tickets
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->pluck('service_type')
            ->unique()
            ->count();

        $priorityWaiting = $waiting->filter(function (QueueTicket $ticket) {
            return $this->isPriority($ticket);
        })->count();

        $waitingCount = $waiting->count();

        return [
            'total_issued' => $tickets->count(),
            'total_served_today' => $completed->count(),

            'currently_waiting' => $waitingCount,
            'waiting' => $waitingCount,
            'called' => $tickets->where('status', 'called')->count(),
            'in_service' => $tickets->where('status', 'in_service')->count(),
            'completed' => $completed->count(),
            'skipped' => $tickets->where('status', 'skipped')->count(),
            'cancelled' => $tickets->where('status', 'cancelled')->count(),
            'no_show' => $tickets->where('status', 'no_show')->count(),

            'emergency_waiting' => $waiting->where('is_emergency', true)->count(),
            'priority_waiting' => $priorityWaiting,

            'average_wait_minutes' => $averageWait,
            'average_service_minutes' => $averageService,

            'total_active_queues' => $activeServices,
            'congestion_level' => $this->classifyCongestion($waitingCount),
        ];
    }

    /**
     * Patient's current active ticket.
     */
    public function getActiveTicketForResident(int $residentProfileId): ?QueueTicket
    {
        $active = QueueTicket::query()
            ->with(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy'])
            ->where('resident_profile_id', $residentProfileId)
            ->forToday()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByRaw("
                CASE status
                    WHEN 'called' THEN 1
                    WHEN 'in_service' THEN 2
                    WHEN 'waiting' THEN 3
                    ELSE 4
                END
            ")
            ->orderBy('queue_position')
            ->first();

        if ($active) {
            return $active;
        }

        return QueueTicket::query()
            ->with(['residentProfile.barangay', 'rhu', 'issuedBy', 'servedBy'])
            ->where('resident_profile_id', $residentProfileId)
            ->forToday()
            ->whereIn('status', ['completed', 'no_show', 'cancelled'])
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    private function resolvePatientFlags(ResidentProfile $profile, array $data): array
    {
        $age = $this->resolveAge($profile);

        return [
            'is_senior' => $age !== null && $age >= 60,
            'is_pregnant' => (bool) (
                $data['is_pregnant']
                ?? $profile->getAttribute('is_pregnant')
                ?? false
            ),
            'is_pwd' => (bool) (
                $data['is_pwd']
                ?? $profile->getAttribute('is_pwd')
                ?? false
            ),
            'is_pediatric' => $age !== null && $age < 5,
            'is_emergency' => (bool) ($data['is_emergency'] ?? false),
            'is_bhw_endorsed' => (bool) ($data['is_bhw_endorsed'] ?? false),
        ];
    }

    private function resolveAge(ResidentProfile $profile): ?int
    {
        $birthdate =
            $profile->getAttribute('birthdate')
            ?? $profile->getAttribute('birth_date')
            ?? $profile->getAttribute('date_of_birth');

        if (!$birthdate) {
            return null;
        }

        try {
            return Carbon::parse($birthdate)->age;
        } catch (Throwable) {
            return null;
        }
    }

    private function computePrioritySafely(
        ResidentProfile $profile,
        array $data,
        array $flags
    ): array {
        try {
            $result = $this->prioritizationService->computePriorityScore(
                profile: $profile,
                context: [
                    'severity_score' => (int) ($data['ai_severity_score'] ?? 0),
                    'is_emergency' => $flags['is_emergency'],
                    'is_pregnant' => $flags['is_pregnant'],
                    'is_pwd' => $flags['is_pwd'],
                    'is_bhw_endorsed' => $flags['is_bhw_endorsed'],
                    'is_telemedicine_escalation' => (bool) ($data['is_telemedicine_escalation'] ?? false),
                    'appointment_id' => $data['appointment_id'] ?? null,
                ]
            );

            return [
                'priority_score' => min(100, max(0, (int) ($result['priority_score'] ?? 0))),
                'priority_category' => $result['priority_category'] ?? 'Low',
                'queue_type' => $result['queue_type'] ?? 'walk_in',
                'breakdown' => $result['breakdown'] ?? [],
                'contributing_factors' => $result['contributing_factors'] ?? [],
            ];
        } catch (Throwable $e) {
            $score = 0;
            $factors = [];
            $breakdown = [];

            if ($flags['is_emergency']) {
                $score += 100;
                $factors[] = 'emergency_case';
                $breakdown['emergency'] = 100;
            }

            if ($flags['is_pregnant']) {
                $score += 25;
                $factors[] = 'pregnant';
                $breakdown['pregnant'] = 25;
            }

            if ($flags['is_senior']) {
                $score += 20;
                $factors[] = 'senior_citizen';
                $breakdown['senior_citizen'] = 20;
            }

            if ($flags['is_pwd']) {
                $score += 20;
                $factors[] = 'pwd';
                $breakdown['pwd'] = 20;
            }

            if ($flags['is_pediatric']) {
                $score += 15;
                $factors[] = 'pediatric';
                $breakdown['pediatric'] = 15;
            }

            if ($flags['is_bhw_endorsed']) {
                $score += 10;
                $factors[] = 'bhw_endorsed';
                $breakdown['bhw_endorsed'] = 10;
            }

            $severity = min(50, max(0, (int) ($data['ai_severity_score'] ?? 0)));
            $score += $severity;

            if ($severity > 0) {
                $factors[] = 'chief_complaint_severity';
                $breakdown['chief_complaint_severity'] = $severity;
            }

            $score = min(100, $score);

            return [
                'priority_score' => $score,
                'priority_category' => $this->priorityLevelFromScore($score, $flags['is_emergency']),
                'queue_type' => $this->resolveQueueTypeFallback($flags, $data),
                'breakdown' => $breakdown,
                'contributing_factors' => $factors,
            ];
        }
    }

    private function resolveTicketPriorityCategory(array $flags, int $score): string
    {
        if ($flags['is_emergency'] || $score >= 80) {
            return 'emergency';
        }

        if ($flags['is_pregnant']) {
            return 'pregnant';
        }

        if ($flags['is_senior']) {
            return 'senior_citizen';
        }

        if ($flags['is_pwd']) {
            return 'pwd';
        }

        if ($flags['is_pediatric']) {
            return 'pediatric';
        }

        return 'regular';
    }

    private function priorityLevelFromScore(int $score, bool $isEmergency): string
    {
        if ($isEmergency || $score >= 80) {
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

    private function resolveQueueTypeFallback(array $flags, array $data): string
    {
        if ($flags['is_emergency']) {
            return 'emergency';
        }

        if ($flags['is_pregnant']) {
            return 'pregnant';
        }

        if ($flags['is_senior']) {
            return 'senior';
        }

        if ($flags['is_pwd']) {
            return 'pwd';
        }

        if (!empty($data['appointment_id'])) {
            return 'pre_registered';
        }

        return 'walk_in';
    }

    private function storePriorityScoreSafely(
        QueueTicket $ticket,
        ResidentProfile $profile,
        array $priorityResult,
        array $data
    ): void {
        if (!Schema::hasTable('queue_priority_scores')) {
            return;
        }

        try {
            $values = [
                'resident_profile_id' => $profile->id,
                'priority_score' => (int) $priorityResult['priority_score'],
                'priority_category' => $priorityResult['priority_category'] ?? 'Low',
                'queue_type' => $priorityResult['queue_type'] ?? 'walk_in',
                'breakdown' => $priorityResult['breakdown'] ?? [],
                'contributing_factors' => $priorityResult['contributing_factors'] ?? [],
                'ai_severity_score' => $data['ai_severity_score'] ?? null,
                'computed_at' => now(),
            ];

            foreach (array_keys($values) as $column) {
                if (!Schema::hasColumn('queue_priority_scores', $column)) {
                    unset($values[$column]);
                }
            }

            QueuePriorityScore::updateOrCreate(
                ['queue_ticket_id' => $ticket->id],
                $values
            );
        } catch (Throwable) {
            // Priority audit record must not break queue issuance.
        }
    }

    private function incrementAndGetCounter(int $rhuId, string $serviceType): QueueCounter
    {
        $counter = QueueCounter::query()
            ->where('rhu_id', $rhuId)
            ->where('service_type', $serviceType)
            ->whereDate('queue_date', today())
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            $counter = QueueCounter::create([
                'rhu_id' => $rhuId,
                'service_type' => $serviceType,
                'queue_date' => today(),
                'last_issued_number' => 0,
                'current_serving_number' => null,
                'is_active' => true,
            ]);
        }

        $counter->increment('last_issued_number');

        return $counter->refresh();
    }

    private function formatTicketNumber(int $rhuId, string $serviceType, int $number): string
    {
        $code = self::SERVICE_CODES[$serviceType]
            ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $serviceType), 0, 3));

        return 'R'
            . $rhuId
            . '-'
            . $code
            . '-'
            . now()->format('ymd')
            . '-'
            . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function reflowQueuePositions(int $rhuId, ?string $serviceType = null): void
    {
        $serviceTypes = $serviceType
            ? [$serviceType]
            : QueueTicket::query()
                ->forRhu($rhuId)
                ->forToday()
                ->where('status', 'waiting')
                ->distinct()
                ->pluck('service_type')
                ->values()
                ->all();

        foreach ($serviceTypes as $type) {
            $waitingTickets = QueueTicket::query()
                ->forRhu($rhuId)
                ->byServiceType((string) $type)
                ->forToday()
                ->where('status', 'waiting')
                ->orderByDesc('is_emergency')
                ->orderByDesc('priority_score')
                ->orderBy('issued_at')
                ->orderBy('id')
                ->get();

            $position = 1;

            foreach ($waitingTickets as $waitingTicket) {
                if ((int) $waitingTicket->queue_position !== $position) {
                    $waitingTicket->update([
                        'queue_position' => $position,
                    ]);
                }

                $position++;
            }
        }
    }

    private function updateCounterCurrentServing(QueueTicket $ticket): void
    {
        try {
            $number = null;

            if (preg_match('/-(\d+)$/', (string) $ticket->ticket_number, $matches)) {
                $number = (int) $matches[1];
            }

            if (!$number) {
                return;
            }

            QueueCounter::query()
                ->where('rhu_id', $ticket->rhu_id)
                ->where('service_type', $ticket->service_type)
                ->whereDate('queue_date', today())
                ->update([
                    'current_serving_number' => $number,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // Counter display should not break the call-next process.
        }
    }

    private function applyAntiStarvationSafely(int $rhuId): void
    {
        try {
            $this->prioritizationService->applyAntiStarvation($rhuId);
        } catch (Throwable) {
            // Queue must keep working even if the scoring helper fails.
        }
    }

    private function writeLog(
        QueueTicket $ticket,
        ?string $from,
        string $to,
        string $action,
        array $metadata = []
    ): void {
        try {
            QueueLog::create([
                'queue_ticket_id' => $ticket->id,
                'performed_by' => Auth::id(),
                'action' => $action,
                'from_status' => $from,
                'to_status' => $to,
                'metadata' => array_merge($metadata, [
                    'ip' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]),
                'performed_at' => now(),
            ]);
        } catch (Throwable) {
            // Logging must not block patient flow.
        }
    }

    private function auditInfoSafely(string $action, QueueTicket $ticket, array $newValues): void
    {
        try {
            $this->audit->info($action, 'queue', [
                'subject' => $ticket,
                'subject_label' => "Queue Ticket #{$ticket->ticket_number}",
                'new_values' => $newValues,
                'metadata' => [
                    'service_type' => $ticket->service_type,
                    'rhu_id' => $ticket->rhu_id,
                ],
            ]);
        } catch (Throwable) {
            // Audit must not interrupt the queue.
        }
    }

    private function minutesBetween(mixed $from, mixed $to): int
    {
        try {
            if (!$from || !$to) {
                return 0;
            }

            return max(0, Carbon::parse($from)->diffInMinutes(Carbon::parse($to)));
        } catch (Throwable) {
            return 0;
        }
    }

    private function isPriority(QueueTicket $ticket): bool
    {
        return (bool) $ticket->is_emergency
            || (bool) $ticket->is_pregnant
            || (bool) $ticket->is_senior
            || (bool) $ticket->is_pwd
            || (bool) $ticket->is_pediatric
            || (bool) $ticket->is_bhw_endorsed
            || (int) $ticket->priority_score >= 35
            || !in_array((string) $ticket->priority_category, ['', 'regular'], true);
    }

    private function classifyCongestion(int $waitingCount): string
    {
        if ($waitingCount >= 50) {
            return 'critical';
        }

        if ($waitingCount >= 30) {
            return 'high';
        }

        if ($waitingCount >= 15) {
            return 'moderate';
        }

        return 'low';
    }
}
