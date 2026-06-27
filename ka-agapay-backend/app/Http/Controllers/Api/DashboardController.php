<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) ($user->user_id ?? $user->id);

        $profile = Schema::hasTable('resident_profiles')
            ? DB::table('resident_profiles')->where('user_id', $userId)->first()
            : null;

        $queueTicket = null;

        if ($profile && Schema::hasTable('queue_tickets')) {
            $queueTicket = DB::table('queue_tickets')
                ->where('resident_profile_id', $profile->id)
                ->whereIn('status', ['waiting', 'called', 'in_service', 'serving'])
                ->latest('issued_at')
                ->first();
        }

        $appointment = null;

        if (Schema::hasTable('appointments')) {
            $appointment = DB::table('appointments')
                ->when(Schema::hasColumn('appointments', 'user_id'), function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->whereNotIn('status', ['cancelled', 'completed', 'rejected'])
                ->orderBy(Schema::hasColumn('appointments', 'appointment_date') ? 'appointment_date' : 'created_at')
                ->first();
        }

        $consultationCount = Schema::hasTable('consultations')
            ? DB::table('consultations')->where('user_id', $userId)->count()
            : 0;

        return response()->json([
            'data' => [
                'profile_completed' => (bool) $profile,
                'active_queue_ticket' => $queueTicket,
                'next_appointment' => $appointment,
                'consultation_count' => $consultationCount,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function admin(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $cards = [
            'patients' => $this->safeCount('users'),
            'active_patients' => $this->activePatientCount(),
            'appointments_today' => $this->appointmentsTodayCount(),
            'open_consultations' => $this->countRows('consultations', function ($q) {
                return Schema::hasColumn('consultations', 'status')
                    ? $q->whereIn('status', ['open', 'ongoing'])
                    : $q;
            }),
            'completed_consultations' => $this->countRows('consultations', function ($q) {
                return Schema::hasColumn('consultations', 'status')
                    ? $q->where('status', 'completed')
                    : $q;
            }),
            'pending_telemedicine' => $this->countRows('telemedicine_requests', function ($q) {
                return Schema::hasColumn('telemedicine_requests', 'status')
                    ? $q->whereIn('status', ['pending', 'screened', 'scheduled'])
                    : $q;
            }),
            'waiting_queue' => $this->countRows('queue_tickets', function ($q) {
                return Schema::hasColumn('queue_tickets', 'status')
                    ? $q->whereIn('status', ['waiting', 'called', 'in_service'])
                    : $q;
            }),
            'low_inventory' => $this->lowInventoryCount(),
            'prescriptions_today' => $this->prescriptionsTodayCount(),
            'active_prescriptions' => $this->activePrescriptionsCount(),

            // Operations-center KPIs (additive — existing keys are unchanged).
            'patients_served_today' => $this->patientsServedToday(),
            'average_wait_minutes' => $this->averageWaitMinutes(),
            'pending_appointments' => $this->pendingAppointmentsCount(),
        ];

        $followUps = $this->followUpCounts();
        $telemedicineWorklist = $this->telemedicineWorklist();
        $queueSnapshot = $this->queueSnapshot();

        $cards['follow_ups_due_today'] = $followUps['due_today'];
        $cards['overdue_follow_ups'] = $followUps['overdue'];
        $cards['telemedicine_needs_soap'] = $telemedicineWorklist['needs_soap'];

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'data' => [
                'cards' => $cards,
                'priority_actions' => $this->priorityActions($followUps, $telemedicineWorklist, $queueSnapshot, $cards),
                'follow_ups' => $followUps,
                'queue_snapshot' => $queueSnapshot,
                'appointments_today' => $this->appointmentsToday(),
                'telemedicine_worklist' => $telemedicineWorklist,
                'consultation_trend' => $this->consultationTrend($from, $to),
                'queue_summary' => $this->queueSummary(),
                'telemedicine_summary' => $this->telemedicineSummary($from, $to),
                'top_barangays' => $this->topBarangays($from, $to),
                'top_complaints' => $this->topComplaints($from, $to),
                'recent_consultations' => $this->recentConsultations(),
                'recent_prescriptions' => $this->recentPrescriptions(),
                'inventory_alerts' => $this->inventoryAlerts(),
            ],
        ]);
    }

    private function dateRange(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();

        return [$from, $to];
    }

    private function safeCount(string $table): int
    {
        return Schema::hasTable($table)
            ? (int) DB::table($table)->count()
            : 0;
    }

    private function countRows(string $table, callable $callback): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $query = $callback($query) ?? $query;

        return (int) $query->count();
    }

    private function activePatientCount(): int
    {
        if (!Schema::hasTable('users')) {
            return 0;
        }

        if (Schema::hasColumn('users', 'account_status')) {
            return (int) DB::table('users')
                ->where('account_status', 'active')
                ->count();
        }

        return (int) DB::table('users')->count();
    }

    private function appointmentsTodayCount(): int
    {
        if (!Schema::hasTable('appointments')) {
            return 0;
        }

        $dateColumn = match (true) {
            Schema::hasColumn('appointments', 'appointment_date') => 'appointment_date',
            Schema::hasColumn('appointments', 'date') => 'date',
            Schema::hasColumn('appointments', 'scheduled_date') => 'scheduled_date',
            default => 'created_at',
        };

        return (int) DB::table('appointments')
            ->whereDate($dateColumn, today())
            ->count();
    }

    private function prescriptionsTodayCount(): int
    {
        if (!Schema::hasTable('prescriptions')) {
            return 0;
        }

        $dateColumn = Schema::hasColumn('prescriptions', 'prescription_date')
            ? 'prescription_date'
            : 'created_at';

        return (int) DB::table('prescriptions')
            ->whereDate($dateColumn, today())
            ->count();
    }

    private function activePrescriptionsCount(): int
    {
        if (!Schema::hasTable('prescriptions')) {
            return 0;
        }

        if (Schema::hasColumn('prescriptions', 'status')) {
            return (int) DB::table('prescriptions')
                ->where('status', 'active')
                ->count();
        }

        return (int) DB::table('prescriptions')->count();
    }

    private function lowInventoryCount(): int
    {
        if (!Schema::hasTable('inventory_items')) {
            return 0;
        }

        if (
            Schema::hasColumn('inventory_items', 'current_stock') &&
            Schema::hasColumn('inventory_items', 'minimum_stock_level')
        ) {
            return (int) DB::table('inventory_items')
                ->whereColumn('current_stock', '<=', 'minimum_stock_level')
                ->count();
        }

        if (
            Schema::hasColumn('inventory_items', 'quantity') &&
            Schema::hasColumn('inventory_items', 'reorder_level')
        ) {
            return (int) DB::table('inventory_items')
                ->whereColumn('quantity', '<=', 'reorder_level')
                ->count();
        }

        if (
            Schema::hasColumn('inventory_items', 'qty') &&
            Schema::hasColumn('inventory_items', 'reorder')
        ) {
            return (int) DB::table('inventory_items')
                ->whereColumn('qty', '<=', 'reorder')
                ->count();
        }

        return 0;
    }

    private function consultationTrend(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('consultations')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('consultations', 'consultation_date')
            ? 'consultation_date'
            : 'created_at';

        return DB::table('consultations')
            ->selectRaw("DATE({$dateColumn}) as date")
            ->selectRaw("COUNT(*) as total")
            ->whereBetween($dateColumn, [$from, $to])
            ->groupByRaw("DATE({$dateColumn})")
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function queueSummary(): array
    {
        if (!Schema::hasTable('queue_tickets')) {
            return [];
        }

        return DB::table('queue_tickets')
            ->selectRaw("COALESCE(status, 'unknown') as status")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(wait_time_minutes) as avg_wait')
            ->groupByRaw("COALESCE(status, 'unknown')")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'total' => (int) $row->total,
                'avg_wait_minutes' => round((float) $row->avg_wait, 1),
            ])
            ->all();
    }

    private function telemedicineSummary(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('telemedicine_sessions')) {
            return [
                'total' => 0,
                'active' => 0,
                'completed' => 0,
                'cancelled' => 0,
            ];
        }

        $base = DB::table('telemedicine_sessions')
            ->whereBetween('created_at', [$from, $to]);

        return [
            'total' => (int) (clone $base)->count(),
            'active' => (int) (clone $base)->whereIn('status', ['waiting', 'active'])->count(),
            'completed' => (int) (clone $base)->whereIn('status', ['ended', 'completed'])->count(),
            'cancelled' => (int) (clone $base)->whereIn('status', ['cancelled', 'no_show'])->count(),
        ];
    }

    private function topBarangays(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('consultations') || !Schema::hasTable('users')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('consultations', 'consultation_date')
            ? 'consultation_date'
            : 'created_at';

        $hasResidentProfiles = Schema::hasTable('resident_profiles')
            && Schema::hasColumn('resident_profiles', 'user_id')
            && Schema::hasColumn('resident_profiles', 'barangay_id');

        $hasBarangays = Schema::hasTable('barangays');

        $hasUserBarangayText = Schema::hasColumn('users', 'barangay');

        $query = DB::table('consultations as c')
            ->join('users as u', 'u.user_id', '=', 'c.user_id');

        if ($hasResidentProfiles) {
            $query->leftJoin('resident_profiles as rp', 'rp.user_id', '=', 'c.user_id');
        }

        if ($hasBarangays && $hasResidentProfiles) {
            $query->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id');
        }

        if ($hasBarangays && $hasResidentProfiles && $hasUserBarangayText) {
            $barangayExpr = "COALESCE(b.name, u.barangay, 'Unspecified')";
        } elseif ($hasBarangays && $hasResidentProfiles) {
            $barangayExpr = "COALESCE(b.name, 'Unspecified')";
        } elseif ($hasUserBarangayText) {
            $barangayExpr = "COALESCE(u.barangay, 'Unspecified')";
        } else {
            $barangayExpr = "'Unspecified'";
        }

        return $query
            ->selectRaw("{$barangayExpr} as barangay")
            ->selectRaw("COUNT(*) as total")
            ->whereBetween("c.{$dateColumn}", [$from, $to])
            ->groupByRaw($barangayExpr)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'barangay' => $row->barangay,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function topComplaints(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('consultations')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('consultations', 'consultation_date')
            ? 'consultation_date'
            : 'created_at';

        $parts = [];

        if (Schema::hasColumn('consultations', 'chief_complaint')) {
            $parts[] = "NULLIF(chief_complaint, '')";
        }

        if (Schema::hasColumn('consultations', 'diagnosis')) {
            $parts[] = "NULLIF(diagnosis, '')";
        }

        if (Schema::hasColumn('consultations', 'assessment')) {
            $parts[] = "NULLIF(assessment, '')";
        }

        $complaintExpr = count($parts) > 0
            ? 'COALESCE(' . implode(', ', $parts) . ", 'Unspecified')"
            : "'Unspecified'";

        return DB::table('consultations')
            ->selectRaw("{$complaintExpr} as complaint")
            ->selectRaw("COUNT(*) as total")
            ->whereBetween($dateColumn, [$from, $to])
            ->groupByRaw($complaintExpr)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'complaint' => $row->complaint,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function recentConsultations(): array
    {
        if (!Schema::hasTable('consultations')) {
            return [];
        }

        return DB::table('consultations as c')
            ->leftJoin('users as u', 'u.user_id', '=', 'c.user_id')
            ->selectRaw("c.id")
            ->selectRaw("c.status")
            ->selectRaw("c.chief_complaint")
            ->selectRaw("c.diagnosis")
            ->selectRaw("c.created_at")
            ->selectRaw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name")
            ->latest('c.created_at')
            ->limit(8)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function recentPrescriptions(): array
    {
        if (!Schema::hasTable('prescriptions')) {
            return [];
        }

        return DB::table('prescriptions as p')
            ->leftJoin('resident_profiles as rp', 'rp.id', '=', 'p.resident_profile_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'rp.user_id')
            ->selectRaw("p.id")
            ->selectRaw("p.prescription_number")
            ->selectRaw("p.status")
            ->selectRaw("p.diagnosis")
            ->selectRaw("p.prescription_date")
            ->selectRaw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name")
            ->latest('p.created_at')
            ->limit(6)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    // =========================================================================
    // OPERATIONS CENTER — counts, worklists, priority actions
    // =========================================================================

    private function patientsServedToday(): int
    {
        if (!Schema::hasTable('queue_tickets')) {
            return 0;
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'completed_at')
            ? 'completed_at'
            : (Schema::hasColumn('queue_tickets', 'service_ended_at') ? 'service_ended_at' : 'updated_at');

        return (int) DB::table('queue_tickets')
            ->where('status', 'completed')
            ->whereDate($dateColumn, today())
            ->count();
    }

    private function averageWaitMinutes(): int
    {
        if (!Schema::hasTable('queue_tickets') || !Schema::hasColumn('queue_tickets', 'wait_time_minutes')) {
            return 0;
        }

        $avg = DB::table('queue_tickets')
            ->whereDate('issued_at', today())
            ->whereNotNull('wait_time_minutes')
            ->avg('wait_time_minutes');

        return (int) round((float) $avg);
    }

    private function pendingAppointmentsCount(): int
    {
        if (!Schema::hasTable('appointments') || !Schema::hasColumn('appointments', 'status')) {
            return 0;
        }

        return (int) DB::table('appointments')
            ->where('status', 'pending')
            ->count();
    }

    private function followUpCounts(): array
    {
        $empty = [
            'total' => 0,
            'overdue' => 0,
            'due_today' => 0,
            'upcoming' => 0,
            'completed_this_month' => 0,
            'missed' => 0,
        ];

        if (!Schema::hasTable('follow_up_reminders')) {
            return $empty;
        }

        $active = ['pending', 'scheduled'];
        $today = today();
        $monthStart = now()->startOfMonth();
        $missedCutoff = today()->subDays(7);

        return [
            'total' => (int) DB::table('follow_up_reminders')->count(),
            'overdue' => (int) DB::table('follow_up_reminders')
                ->whereIn('status', $active)
                ->whereNotNull('follow_up_at')
                ->whereDate('follow_up_at', '<', $today)
                ->count(),
            'due_today' => (int) DB::table('follow_up_reminders')
                ->whereIn('status', $active)
                ->whereDate('follow_up_at', $today)
                ->count(),
            'upcoming' => (int) DB::table('follow_up_reminders')
                ->whereIn('status', $active)
                ->whereDate('follow_up_at', '>', $today)
                ->count(),
            'completed_this_month' => (int) DB::table('follow_up_reminders')
                ->where('status', 'completed')
                ->where('updated_at', '>=', $monthStart)
                ->count(),
            'missed' => (int) DB::table('follow_up_reminders')
                ->where(function ($q) use ($active, $missedCutoff) {
                    $q->where('status', 'missed')
                        ->orWhere(function ($q2) use ($active, $missedCutoff) {
                            $q2->whereIn('status', $active)
                                ->whereNotNull('follow_up_at')
                                ->whereDate('follow_up_at', '<', $missedCutoff);
                        });
                })
                ->count(),
        ];
    }

    private function telemedicineWorklist(): array
    {
        $worklist = [
            'pending_screening' => 0,
            'scheduled' => 0,
            'active' => 0,
            'needs_soap' => 0,
            'completed_today' => 0,
        ];

        if (Schema::hasTable('telemedicine_requests')) {
            $worklist['pending_screening'] = (int) DB::table('telemedicine_requests')
                ->whereIn('status', ['pending', 'screened'])
                ->count();

            $worklist['scheduled'] = (int) DB::table('telemedicine_requests')
                ->where('status', 'scheduled')
                ->count();
        }

        if (Schema::hasTable('telemedicine_sessions')) {
            $worklist['active'] = (int) DB::table('telemedicine_sessions')
                ->whereIn('status', ['active', 'waiting', 'paused'])
                ->count();

            $worklist['needs_soap'] = (int) DB::table('telemedicine_sessions')
                ->where('status', 'ended')
                ->when(Schema::hasColumn('telemedicine_sessions', 'consultation_id'), function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNull('consultation_id')
                            ->orWhereNotExists(function ($sub) {
                                $sub->selectRaw('1')
                                    ->from('consultations')
                                    ->whereColumn('consultations.id', 'telemedicine_sessions.consultation_id')
                                    ->where('consultations.status', 'completed');
                            });
                    });
                })
                ->count();

            $endedColumn = Schema::hasColumn('telemedicine_sessions', 'ended_at') ? 'ended_at' : 'updated_at';

            $worklist['completed_today'] = (int) DB::table('telemedicine_sessions')
                ->where('status', 'ended')
                ->whereDate($endedColumn, today())
                ->count();
        }

        return $worklist;
    }

    private function queueSnapshot(): array
    {
        $snapshot = [
            'waiting' => 0,
            'called' => 0,
            'serving' => 0,
            'completed' => 0,
            'no_show' => 0,
            'skipped' => 0,
            'priority_waiting' => 0,
            'average_wait_minutes' => $this->averageWaitMinutes(),
        ];

        if (!Schema::hasTable('queue_tickets')) {
            return $snapshot;
        }

        $today = today();

        $snapshot['waiting'] = (int) DB::table('queue_tickets')->where('status', 'waiting')->whereDate('issued_at', $today)->count();
        $snapshot['called'] = (int) DB::table('queue_tickets')->where('status', 'called')->whereDate('issued_at', $today)->count();
        $snapshot['serving'] = (int) DB::table('queue_tickets')->whereIn('status', ['in_service', 'serving'])->whereDate('issued_at', $today)->count();
        $snapshot['completed'] = (int) DB::table('queue_tickets')->where('status', 'completed')->whereDate('issued_at', $today)->count();
        $snapshot['no_show'] = (int) DB::table('queue_tickets')->where('status', 'no_show')->whereDate('issued_at', $today)->count();
        $snapshot['skipped'] = (int) DB::table('queue_tickets')->where('status', 'skipped')->whereDate('issued_at', $today)->count();

        if (Schema::hasColumn('queue_tickets', 'priority_score')) {
            $snapshot['priority_waiting'] = (int) DB::table('queue_tickets')
                ->where('status', 'waiting')
                ->whereDate('issued_at', $today)
                ->where('priority_score', '>=', 35)
                ->count();
        }

        return $snapshot;
    }

    private function appointmentsToday(): array
    {
        if (!Schema::hasTable('appointments')) {
            return [];
        }

        $dateColumn = match (true) {
            Schema::hasColumn('appointments', 'appointment_date') => 'appointment_date',
            Schema::hasColumn('appointments', 'scheduled_date') => 'scheduled_date',
            default => 'created_at',
        };

        $query = DB::table('appointments as a')
            ->leftJoin('users as u', 'u.user_id', '=', 'a.user_id')
            ->selectRaw('a.id')
            ->selectRaw('a.status')
            ->selectRaw('a.appointment_time')
            ->selectRaw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name")
            ->whereDate("a.{$dateColumn}", today());

        if (Schema::hasColumn('appointments', 'consultation_type')) {
            $query->addSelect(DB::raw('a.consultation_type'));
        }

        if (Schema::hasColumn('appointments', 'reason')) {
            $query->addSelect(DB::raw('a.reason'));
        }

        return $query
            ->orderByRaw("CASE a.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'scheduled' THEN 3 WHEN 'ongoing' THEN 4 ELSE 5 END")
            ->orderBy('a.appointment_time')
            ->limit(12)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Build a sorted "what should staff do next" feed. Highest urgency first.
     */
    private function priorityActions(array $followUps, array $telemedicine, array $queue, array $cards): array
    {
        $actions = [];

        // 1) Urgent / pending telemedicine screening.
        if (($telemedicine['pending_screening'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'telemedicine',
                'priority' => 'high',
                'title' => 'Telemedicine needs screening',
                'patient_name' => null,
                'description' => $telemedicine['pending_screening'] . ' online request(s) waiting for RHU screening.',
                'count' => (int) $telemedicine['pending_screening'],
                'action_label' => 'Open Telemedicine',
                'action_url' => '/telemedicine',
            ];
        }

        // 2) Overdue follow-ups.
        if (($followUps['overdue'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'follow_up',
                'priority' => 'high',
                'title' => 'Overdue follow-ups',
                'patient_name' => null,
                'description' => $followUps['overdue'] . ' follow-up(s) are past their schedule.',
                'count' => (int) $followUps['overdue'],
                'action_label' => 'View Follow-ups',
                'action_url' => '/follow-up',
            ];
        }

        // 3) Due today follow-ups.
        if (($followUps['due_today'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'follow_up',
                'priority' => 'medium',
                'title' => 'Follow-ups due today',
                'patient_name' => null,
                'description' => $followUps['due_today'] . ' follow-up(s) are due today.',
                'count' => (int) $followUps['due_today'],
                'action_label' => 'View Follow-ups',
                'action_url' => '/follow-up',
            ];
        }

        // 4) Priority queue patients waiting.
        if (($queue['priority_waiting'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'queue',
                'priority' => 'high',
                'title' => 'Priority patients waiting',
                'patient_name' => null,
                'description' => $queue['priority_waiting'] . ' priority patient(s) waiting in the queue.',
                'count' => (int) $queue['priority_waiting'],
                'action_label' => 'View Queue',
                'action_url' => '/queue',
            ];
        } elseif (($queue['waiting'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'queue',
                'priority' => 'medium',
                'title' => 'Patients waiting in queue',
                'patient_name' => null,
                'description' => $queue['waiting'] . ' patient(s) waiting to be served.',
                'count' => (int) $queue['waiting'],
                'action_label' => 'View Queue',
                'action_url' => '/queue',
            ];
        }

        // 5) Telemedicine sessions needing SOAP finalization.
        if (($telemedicine['needs_soap'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'soap',
                'priority' => 'medium',
                'title' => 'Telemedicine needs SOAP',
                'patient_name' => null,
                'description' => $telemedicine['needs_soap'] . ' ended session(s) awaiting SOAP finalization.',
                'count' => (int) $telemedicine['needs_soap'],
                'action_label' => 'Open Telemedicine',
                'action_url' => '/telemedicine',
            ];
        }

        // 6) Pending appointments to approve/schedule.
        if (($cards['pending_appointments'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'appointment',
                'priority' => 'medium',
                'title' => 'Appointments need action',
                'patient_name' => null,
                'description' => $cards['pending_appointments'] . ' appointment(s) need approval or scheduling.',
                'count' => (int) $cards['pending_appointments'],
                'action_label' => 'Open Appointments',
                'action_url' => '/appointments',
            ];
        }

        // 7) Low stock / restock.
        if (($cards['low_inventory'] ?? 0) > 0) {
            $actions[] = [
                'type' => 'inventory',
                'priority' => 'low',
                'title' => 'Low stock items',
                'patient_name' => null,
                'description' => $cards['low_inventory'] . ' item(s) need restocking.',
                'count' => (int) $cards['low_inventory'],
                'action_label' => 'Open Inventory',
                'action_url' => '/inventory',
            ];
        }

        return $actions;
    }

    private function inventoryAlerts(): array
    {
        if (!Schema::hasTable('inventory_items')) {
            return [];
        }

        $nameColumn = Schema::hasColumn('inventory_items', 'name')
            ? 'name'
            : (Schema::hasColumn('inventory_items', 'item_name') ? 'item_name' : null);

        if (!$nameColumn) {
            return [];
        }

        if (
            Schema::hasColumn('inventory_items', 'current_stock') &&
            Schema::hasColumn('inventory_items', 'minimum_stock_level')
        ) {
            $qtyColumn = 'current_stock';
            $reorderColumn = 'minimum_stock_level';
        } elseif (
            Schema::hasColumn('inventory_items', 'quantity') &&
            Schema::hasColumn('inventory_items', 'reorder_level')
        ) {
            $qtyColumn = 'quantity';
            $reorderColumn = 'reorder_level';
        } elseif (
            Schema::hasColumn('inventory_items', 'qty') &&
            Schema::hasColumn('inventory_items', 'reorder')
        ) {
            $qtyColumn = 'qty';
            $reorderColumn = 'reorder';
        } else {
            return [];
        }

        return DB::table('inventory_items')
            ->selectRaw("id")
            ->selectRaw("{$nameColumn} as name")
            ->selectRaw("{$qtyColumn} as quantity")
            ->selectRaw("{$reorderColumn} as reorder_level")
            ->whereColumn($qtyColumn, '<=', $reorderColumn)
            ->orderBy($qtyColumn)
            ->limit(10)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}