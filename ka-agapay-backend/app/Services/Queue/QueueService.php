<?php
// app/Services/Queue/QueueService.php

namespace App\Services\Queue;

use App\Models\QueueTicket;
use App\Models\QueueCounter;
use App\Models\QueuePriorityRule;
use App\Models\ResidentProfile;
use App\Models\QueueLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuditActions;

class QueueService
{
    public function __construct(
        private readonly AuditService $audit
    ) {}

    /**
     * Issue a new queue ticket for a resident.
     * Computes priority score, assigns a ticket number, and persists.
     */
    public function issueTicket(array $data): QueueTicket
    {
        return DB::transaction(function () use ($data) {
            $residentProfile = ResidentProfile::findOrFail($data['resident_profile_id']);

            // Determine patient flags from their profile
            $flags = $this->resolvePatientFlags($residentProfile, $data);

            // Compute priority score from active rules
            $priorityScore = $this->computePriorityScore($flags);

            // Determine priority category label
            $priorityCategory = $this->resolvePriorityCategory($flags);

            // Generate the sequential ticket number (with row lock to prevent race conditions)
            $counter = $this->incrementAndGetCounter(
                $data['rhu_id'],
                $data['service_type']
            );

            $ticketNumber = $this->formatTicketNumber(
                $data['rhu_id'],
                $data['service_type'],
                $counter->last_issued_number
            );

            // Compute queue position among current waiting tickets
            $queuePosition = $this->computeQueuePosition(
                $data['rhu_id'],
                $data['service_type'],
                $priorityScore
            );

            $ticket = QueueTicket::create([
                'ticket_number'       => $ticketNumber,
                'resident_profile_id' => $data['resident_profile_id'],
                'appointment_id'      => $data['appointment_id'] ?? null,
                'rhu_id'              => $data['rhu_id'],
                'issued_by'           => Auth::id(),
                'service_type'        => $data['service_type'],
                'priority_score'      => $priorityScore,
                'priority_category'   => $priorityCategory,
                'is_senior'           => $flags['is_senior'],
                'is_pregnant'         => $flags['is_pregnant'],
                'is_pwd'              => $flags['is_pwd'],
                'is_pediatric'        => $flags['is_pediatric'],
                'is_emergency'        => $flags['is_emergency'],
                'is_bhw_endorsed'     => $flags['is_bhw_endorsed'],
                'status'              => 'waiting',
                'queue_position'      => $queuePosition,
                'call_attempt'        => 0,
                'issued_at'           => now(),
                'notes'               => $data['notes'] ?? null,
            ]);

            // Write immutable audit log
            $this->writeLog($ticket, null, 'waiting', 'issued', [
                'priority_score'    => $priorityScore,
                'priority_category' => $priorityCategory,
                'queue_position'    => $queuePosition,
                'flags'             => $flags,
            ]);

            $this->audit->info(AuditActions::QUEUE_TICKET_ISSUED, 'queue', [
                'subject'       => $ticket,
                'subject_label' => "Queue Ticket #{$ticket->ticket_number}",
                'new_values'    => ['status' => 'waiting'],
                'metadata'      => [
                    'priority_score'    => $priorityScore,
                    'priority_category' => $priorityCategory,
                ],
            ]);

            return $ticket->fresh(['residentProfile', 'rhu', 'issuedBy']);
        });
    }

    /**
     * Transition a ticket to a new status with validation.
     */
    public function transitionStatus(QueueTicket $ticket, string $newStatus, array $data = []): QueueTicket
    {
        if (!$ticket->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Cannot transition ticket [{$ticket->ticket_number}] from [{$ticket->status}] to [{$newStatus}]."
            );
        }

        return DB::transaction(function () use ($ticket, $newStatus, $data) {
            $fromStatus = $ticket->status;
            $now = now();
            $updates = ['status' => $newStatus];
            $metadata = [];

            switch ($newStatus) {
                case 'called':
                    $updates['called_at']     = $now;
                    $updates['call_attempt']  = $ticket->call_attempt + 1;
                    $metadata['call_attempt'] = $updates['call_attempt'];

                    // Update the counter's current serving number
                    QueueCounter::where('rhu_id', $ticket->rhu_id)
                        ->where('service_type', $ticket->service_type)
                        ->whereDate('queue_date', today())
                        ->update(['current_serving_number' => $this->extractSequentialNumber($ticket->ticket_number)]);
                    break;

                case 'in_service':
                    $updates['service_started_at'] = $now;
                    $updates['served_by']           = Auth::id();
                    if ($ticket->called_at) {
                        $metadata['wait_time_minutes'] = (int) $ticket->called_at->diffInMinutes($ticket->issued_at);
                        $updates['wait_time_minutes']  = $metadata['wait_time_minutes'];
                    }
                    break;

                case 'completed':
                    $updates['service_ended_at'] = $now;
                    if ($ticket->service_started_at) {
                        $updates['service_time_minutes'] = (int) $now->diffInMinutes($ticket->service_started_at);
                        $metadata['service_time_minutes'] = $updates['service_time_minutes'];
                    }
                    break;

                case 'cancelled':
                    $updates['cancelled_at']          = $now;
                    $updates['cancellation_reason']   = $data['cancellation_reason'] ?? 'No reason provided';
                    $metadata['cancellation_reason']  = $updates['cancellation_reason'];
                    break;

                case 'skipped':
                    $metadata['skip_reason'] = $data['notes'] ?? 'Patient did not respond';
                    break;

                case 'no_show':
                    $updates['cancelled_at'] = $now;
                    break;
            }

            if (!empty($data['notes'])) {
                $updates['notes'] = $data['notes'];
            }

            $ticket->update($updates);

            $this->writeLog($ticket, $fromStatus, $newStatus, $newStatus, $metadata);

            // Determine audit action constant based on new status
            $actionMap = [
                'called'     => AuditActions::QUEUE_TICKET_CALLED,
                'in_service' => AuditActions::QUEUE_TICKET_IN_SERVICE,
                'completed'  => AuditActions::QUEUE_TICKET_COMPLETED,
                'cancelled'  => AuditActions::QUEUE_TICKET_CANCELLED,
                'skipped'    => AuditActions::QUEUE_TICKET_SKIPPED,
                'no_show'    => AuditActions::QUEUE_TICKET_NO_SHOW,
            ];

            $action = $actionMap[$newStatus] ?? 'queue_ticket.updated';

            $this->audit->info($action, 'queue', [
                'subject'       => $ticket,
                'subject_label' => "Queue Ticket #{$ticket->ticket_number}",
                'old_values'    => ['status' => $fromStatus],
                'new_values'    => ['status' => $newStatus],
                'metadata'      => $metadata,
            ]);

            return $ticket->fresh(['residentProfile', 'rhu', 'servedBy', 'logs']);
        });
    }

    /**
     * Get the next ticket to be served (called by staff to call the next patient).
     */
    public function callNext(int $rhuId, string $serviceType): ?QueueTicket
    {
        return DB::transaction(function () use ($rhuId, $serviceType) {
            $next = QueueTicket::forRhu($rhuId)
                ->byServiceType($serviceType)
                ->forToday()
                ->waiting()
                ->prioritized()
                ->lockForUpdate()
                ->first();

            if (!$next) {
                return null;
            }

            return $this->transitionStatus($next, 'called');
        });
    }

    /**
     * Get the current live queue for display (e.g., TV monitor or staff dashboard).
     */
    public function getLiveQueue(int $rhuId, ?string $serviceType = null): array
    {
        $query = QueueTicket::with(['residentProfile'])
            ->forRhu($rhuId)
            ->forToday()
            ->whereIn('status', ['waiting', 'called', 'in_service'])
            ->prioritized();

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
     * Get daily queue summary statistics for dashboard.
     */
    public function getDailySummary(int $rhuId, ?string $date = null): array
    {
        $date = $date ? Carbon::parse($date) : today();

        $tickets = QueueTicket::forRhu($rhuId)
            ->whereDate('issued_at', $date)
            ->get();

        $completed = $tickets->where('status', 'completed');

        return [
            'date'                     => $date->toDateString(),
            'total_issued'             => $tickets->count(),
            'waiting'                  => $tickets->where('status', 'waiting')->count(),
            'called'                   => $tickets->where('status', 'called')->count(),
            'in_service'               => $tickets->where('status', 'in_service')->count(),
            'completed'                => $completed->count(),
            'cancelled'                => $tickets->where('status', 'cancelled')->count(),
            'no_show'                  => $tickets->where('status', 'no_show')->count(),
            'skipped'                  => $tickets->where('status', 'skipped')->count(),
            'priority_served'          => $completed->where('priority_category', '!=', 'regular')->count(),
            'avg_wait_time_minutes'    => round($completed->avg('wait_time_minutes') ?? 0, 1),
            'avg_service_time_minutes' => round($completed->avg('service_time_minutes') ?? 0, 1),
            'by_service_type'          => $tickets->groupBy('service_type')
                ->map(fn($group) => [
                    'total'     => $group->count(),
                    'completed' => $group->where('status', 'completed')->count(),
                    'waiting'   => $group->where('status', 'waiting')->count(),
                ])->toArray(),
            'by_priority_category' => $tickets->groupBy('priority_category')
                ->map(fn($group) => $group->count())
                ->toArray(),
        ];
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    /**
     * Resolve boolean patient flags from profile data + request overrides.
     */
    private function resolvePatientFlags(ResidentProfile $profile, array $data): array
    {
        $age = $profile->birthdate
            ? Carbon::parse($profile->birthdate)->age
            : null;

        return [
            'is_senior'       => $age !== null && $age >= 60,
            'is_pregnant'     => (bool) ($profile->is_pregnant ?? false),
            'is_pwd'          => (bool) ($profile->is_pwd ?? false),
            'is_pediatric'    => $age !== null && $age < 5,
            'is_emergency'    => (bool) ($data['is_emergency'] ?? false),
            'is_bhw_endorsed' => (bool) ($data['is_bhw_endorsed'] ?? false),
        ];
    }

    /**
     * Compute numeric priority score by summing weights of active rules that apply.
     * Higher score = higher priority = served first.
     */
    private function computePriorityScore(array $flags): int
    {
        $rules = QueuePriorityRule::where('is_active', true)->get();
        $score = 0;

        foreach ($rules as $rule) {
            if (!empty($flags[$rule->rule_key])) {
                $score += $rule->score_weight;
            }
        }

        return min($score, 255); // cap at 255 (fits unsigned tinyint)
    }

    /**
     * Determine the human-readable priority category based on flags.
     * Emergency always wins; then cascades by priority.
     */
    private function resolvePriorityCategory(array $flags): string
    {
        if ($flags['is_emergency'])  return 'emergency';
        if ($flags['is_pregnant'])   return 'pregnant';
        if ($flags['is_senior'])     return 'senior_citizen';
        if ($flags['is_pwd'])        return 'pwd';
        if ($flags['is_pediatric'])  return 'pediatric';
        return 'regular';
    }

    /**
     * Atomically increment the counter for today's queue and return it.
     */
    private function incrementAndGetCounter(int $rhuId, string $serviceType): QueueCounter
    {
        $counter = QueueCounter::lockForUpdate()->firstOrCreate(
            [
                'rhu_id'       => $rhuId,
                'service_type' => $serviceType,
                'queue_date'   => today(),
            ],
            [
                'last_issued_number' => 0,
                'is_active'          => true,
            ]
        );

        $counter->increment('last_issued_number');
        $counter->refresh();

        return $counter;
    }

    /**
     * Format ticket number as: RHU{id}-{SERVICE_ABBR}-{YYYY}-{NNNN}
     * Example: RHU1-OPD-2024-0001
     */
    private function formatTicketNumber(int $rhuId, string $serviceType, int $sequentialNumber): string
    {
        $abbr = [
            'opd_consultation' => 'OPD',
            'prenatal_checkup' => 'PRE',
            'immunization'     => 'IMM',
            'family_planning'  => 'FP',
            'tb_dots'          => 'TB',
            'laboratory'       => 'LAB',
            'dental'           => 'DEN',
            'emergency'        => 'EMG',
            'medicine_release' => 'MED',
            'bhw_assisted'     => 'BHW',
        ][$serviceType] ?? 'GEN';

        return sprintf('RHU%d-%s-%s-%04d', $rhuId, $abbr, now()->year, $sequentialNumber);
    }

    /**
     * Compute the approximate queue position for a newly issued ticket
     * based on how many waiting tickets have a higher or equal priority score.
     */
    private function computeQueuePosition(int $rhuId, string $serviceType, int $priorityScore): int
    {
        // Position = number of tickets that will be served BEFORE this one + 1
        $ahead = QueueTicket::forRhu($rhuId)
            ->byServiceType($serviceType)
            ->forToday()
            ->waiting()
            ->where(function ($q) use ($priorityScore) {
                $q->where('priority_score', '>', $priorityScore)
                  ->orWhere(function ($q2) use ($priorityScore) {
                      $q2->where('priority_score', '=', $priorityScore)
                         ->whereColumn('issued_at', '<', DB::raw('NOW()'));
                  });
            })
            ->count();

        return $ahead + 1;
    }

    /**
     * Extract the sequential integer from a formatted ticket number.
     */
    private function extractSequentialNumber(string $ticketNumber): int
    {
        $parts = explode('-', $ticketNumber);
        return (int) end($parts);
    }

    /**
     * Write an immutable queue log entry.
     */
    private function writeLog(QueueTicket $ticket, ?string $from, string $to, string $action, array $metadata = []): void
    {
        QueueLog::create([
            'queue_ticket_id' => $ticket->id,
            'performed_by'    => Auth::id(),
            'action'          => $action,
            'from_status'     => $from,
            'to_status'       => $to,
            'metadata'        => array_merge($metadata, [
                'ip'         => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]),
            'performed_at'    => now(),
        ]);
    }
}