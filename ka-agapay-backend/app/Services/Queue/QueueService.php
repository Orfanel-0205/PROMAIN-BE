<?php

namespace App\Services\Queue;

use App\Models\QueueTicket;
use App\Models\QueueCounter;
use App\Models\QueuePriorityRule;
use App\Models\ResidentProfile;
use App\Models\QueueLog;
use App\Models\QueuePriorityScore;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

use App\Services\Audit\AuditService;
use App\Services\Audit\AuditActions;
use App\Services\Queue\QueuePrioritizationService;

class QueueService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly QueuePrioritizationService $prioritizationService
    ) {}

    /**
     * Issue a new queue ticket for a resident.
     */
    public function issueTicket(array $data): QueueTicket
    {
        return DB::transaction(function () use ($data) {

            $residentProfile = ResidentProfile::findOrFail($data['resident_profile_id']);

            // 1. Resolve flags
            $flags = $this->resolvePatientFlags($residentProfile, $data);

            // 2. Counter + ticket number
            $counter = $this->incrementAndGetCounter(
                $data['rhu_id'],
                $data['service_type']
            );

            $ticketNumber = $this->formatTicketNumber(
                $data['rhu_id'],
                $data['service_type'],
                $counter->last_issued_number
            );

            // 3. Create base ticket first
            $ticket = QueueTicket::create([
                'ticket_number'       => $ticketNumber,
                'resident_profile_id' => $data['resident_profile_id'],
                'appointment_id'      => $data['appointment_id'] ?? null,
                'rhu_id'              => $data['rhu_id'],
                'issued_by'           => Auth::id(),
                'service_type'        => $data['service_type'],

                // temporary defaults (updated after scoring)
                'priority_score'      => 0,
                'priority_category'   => 'regular',
                'queue_type'          => 'walk_in',

                'is_senior'           => $flags['is_senior'],
                'is_pregnant'         => $flags['is_pregnant'],
                'is_pwd'              => $flags['is_pwd'],
                'is_pediatric'        => $flags['is_pediatric'],
                'is_emergency'        => $flags['is_emergency'],
                'is_bhw_endorsed'     => $flags['is_bhw_endorsed'],

                'status'              => 'waiting',
                'queue_position'      => 0,
                'call_attempt'        => 0,
                'issued_at'           => now(),
                'notes'               => $data['notes'] ?? null,
            ]);

            // 4. AI + Priority Engine (SINGLE SOURCE OF TRUTH)
            $priorityResult = $this->prioritizationService->computePriorityScore(
                profile: $residentProfile,
                context: [
                    'severity_score'              => $data['ai_severity_score'] ?? 0,
                    'is_emergency'               => $flags['is_emergency'],
                    'is_pregnant'                => $flags['is_pregnant'],
                    'is_pwd'                     => $flags['is_pwd'],
                    'is_bhw_endorsed'            => $flags['is_bhw_endorsed'],
                    'is_telemedicine_escalation' => $data['is_telemedicine_escalation'] ?? false,
                    'appointment_id'             => $data['appointment_id'] ?? null,
                ]
            );

            // 5. Save priority audit record
            QueuePriorityScore::updateOrCreate(
                ['queue_ticket_id' => $ticket->id],
                [
                    'resident_profile_id'  => $residentProfile->id,
                    'priority_score'       => $priorityResult['priority_score'],
                    'priority_category'    => $priorityResult['priority_category'],
                    'queue_type'           => $priorityResult['queue_type'],
                    'breakdown'            => $priorityResult['breakdown'],
                    'contributing_factors' => $priorityResult['contributing_factors'],
                    'ai_severity_score'    => $data['ai_severity_score'] ?? null,
                    'computed_at'          => now(),
                ]
            );

            // 6. Update ticket with final priority values
            $ticket->update([
                'priority_score'    => $priorityResult['priority_score'],
                'priority_category' => $priorityResult['priority_category'],
                'queue_type'        => $priorityResult['queue_type'],
            ]);

            // 7. Compute queue position
            $queuePosition = $this->computeQueuePosition(
                $data['rhu_id'],
                $data['service_type'],
                $priorityResult['priority_score']
            );

            $ticket->update([
                'queue_position' => $queuePosition,
            ]);

            // 8. Audit log
            $this->audit->info(AuditActions::QUEUE_TICKET_ISSUED, 'queue', [
                'subject'       => $ticket,
                'subject_label' => "Queue Ticket #{$ticket->ticket_number}",
                'new_values'    => ['status' => 'waiting'],
                'metadata'      => [
                    'priority_score'    => $priorityResult['priority_score'],
                    'priority_category' => $priorityResult['priority_category'],
                ],
            ]);

            return $ticket->fresh(['residentProfile', 'rhu', 'issuedBy']);
        });
    }

    /**
     * Transition ticket status
     */
    public function transitionStatus(QueueTicket $ticket, string $newStatus, array $data = []): QueueTicket
    {
        if (!$ticket->canTransitionTo($newStatus)) {
            throw new \DomainException("Invalid transition.");
        }

        return DB::transaction(function () use ($ticket, $newStatus, $data) {

            $fromStatus = $ticket->status;
            $now = now();

            $updates = ['status' => $newStatus];
            $metadata = [];

            switch ($newStatus) {

                case 'called':
                    $updates['called_at'] = $now;
                    $updates['call_attempt'] = $ticket->call_attempt + 1;
                    break;

                case 'in_service':
                    $updates['service_started_at'] = $now;
                    $updates['served_by'] = Auth::id();
                    break;

                case 'completed':
                    $updates['service_ended_at'] = $now;
                    break;

                case 'cancelled':
                    $updates['cancelled_at'] = $now;
                    break;

                case 'skipped':
                    $metadata['skip_reason'] = $data['notes'] ?? null;
                    break;
            }

            $ticket->update($updates);

            $this->writeLog($ticket, $fromStatus, $newStatus, $newStatus, $metadata);

            return $ticket->fresh();
        });
    }

    /**
     * Call next ticket
     */
    public function callNext(int $rhuId, string $serviceType): ?QueueTicket
    {
        return DB::transaction(function () use ($rhuId, $serviceType) {

            $next = QueueTicket::forRhu($rhuId)
                ->byServiceType($serviceType)
                ->forToday()
                ->waiting()
                ->orderByDesc('priority_score')
                ->lockForUpdate()
                ->first();

            if (!$next) return null;

            return $this->transitionStatus($next, 'called');
        });
    }

    /**
     * Live queue
     */
    public function getLiveQueue(int $rhuId, ?string $serviceType = null): array
    {
        $query = QueueTicket::forRhu($rhuId)
            ->forToday()
            ->whereIn('status', ['waiting', 'called', 'in_service'])
            ->orderByDesc('priority_score');

        if ($serviceType) {
            $query->byServiceType($serviceType);
        }

        $tickets = $query->get();

        return [
            'waiting'    => $tickets->where('status', 'waiting')->values(),
            'called'     => $tickets->where('status', 'called')->values(),
            'in_service' => $tickets->where('status', 'in_service')->values(),
        ];
    }

    /**
     * Daily summary
     */
    public function getDailySummary(int $rhuId, ?string $date = null): array
    {
        $date = $date ? Carbon::parse($date) : today();

        $tickets = QueueTicket::forRhu($rhuId)
            ->whereDate('issued_at', $date)
            ->get();

        return [
            'total_issued' => $tickets->count(),
            'waiting'      => $tickets->where('status', 'waiting')->count(),
            'completed'    => $tickets->where('status', 'completed')->count(),
        ];
    }

    // ---------------- HELPERS ----------------

    private function resolvePatientFlags(ResidentProfile $profile, array $data): array
    {
        $age = $profile->birthdate ? Carbon::parse($profile->birthdate)->age : null;

        return [
            'is_senior'    => $age >= 60,
            'is_pregnant'  => (bool) $profile->is_pregnant,
            'is_pwd'       => (bool) $profile->is_pwd,
            'is_pediatric' => $age < 5,
            'is_emergency' => (bool) ($data['is_emergency'] ?? false),
            'is_bhw_endorsed' => (bool) ($data['is_bhw_endorsed'] ?? false),
        ];
    }

    private function incrementAndGetCounter(int $rhuId, string $serviceType): QueueCounter
    {
        $counter = QueueCounter::lockForUpdate()->firstOrCreate(
            [
                'rhu_id' => $rhuId,
                'service_type' => $serviceType,
                'queue_date' => today(),
            ],
            ['last_issued_number' => 0]
        );

        $counter->increment('last_issued_number');
        return $counter->refresh();
    }

    private function formatTicketNumber(int $rhuId, string $serviceType, int $num): string
    {
        return "RHU{$rhuId}-{$serviceType}-" . now()->year . "-" . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    private function computeQueuePosition(int $rhuId, string $serviceType, int $score): int
    {
        return QueueTicket::forRhu($rhuId)
            ->byServiceType($serviceType)
            ->forToday()
            ->waiting()
            ->where('priority_score', '>', $score)
            ->count() + 1;
    }

    private function writeLog(QueueTicket $ticket, ?string $from, string $to, string $action, array $meta = []): void
    {
        QueueLog::create([
            'queue_ticket_id' => $ticket->id,
            'performed_by' => Auth::id(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'metadata' => array_merge($meta, [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]),
            'performed_at' => now(),
        ]);
    }
}