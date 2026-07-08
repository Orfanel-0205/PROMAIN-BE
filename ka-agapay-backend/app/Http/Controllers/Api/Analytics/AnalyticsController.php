<?php
// app/Http/Controllers/Api/Analytics/AnalyticsController.php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiTriageService;
use App\Services\Analytics\HeatmapAnalyticsService;
use App\Services\Reports\DiagnosisItrReportService;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AnalyticsController extends Controller
{
    public function realtime(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date', 'after_or_equal:from'],
            'disease' => ['nullable', 'string', 'max:100'],
            'rhu_id'  => ['nullable'],
        ]);

        [$from, $to] = $this->dateRange($request);

        $disease = trim((string) ($validated['disease'] ?? ''));
        $rhuId = $this->resolveRhuId($request);

        $barangayCases = $this->barangayCases($from, $to, $disease, $rhuId);

        $heatmap = collect($barangayCases)
            ->map(function ($row) {
                $cases = (int) ($row['total_cases'] ?? 0);
                $queue = (int) ($row['queue_density'] ?? 0);
                $score = min(100, ($cases * 7) + ($queue * 3));

                return array_merge($row, [
                    'heatmap_intensity' => $score,
                    'risk_score' => $score,
                    'risk_level' => $this->riskLevel($score),
                    'top_case_type' => $row['top_complaint'] ?? $row['top_case_type'] ?? 'Unspecified',
                ]);
            })
            ->values()
            ->all();

        $risk = collect($heatmap)
            ->sortByDesc('risk_score')
            ->values()
            ->all();

        $clusters = collect($this->complaintDistribution($from, $to, $rhuId))
            ->when($disease !== '', function ($items) use ($disease) {
                $needle = Str::lower($disease);

                return $items->filter(function ($item) use ($needle) {
                    $label = Str::lower((string) (
                        data_get($item, 'complaint')
                        ?? data_get($item, 'disease')
                        ?? data_get($item, 'diagnosis')
                        ?? data_get($item, 'top_complaint')
                        ?? ''
                    ));

                    return Str::contains($label, $needle);
                });
            })
            ->values()
            ->all();

        $chatbotPayload = $this->chatbotUsage($request)->getData(true);

        $chatbot = $chatbotPayload['data'] ?? [
            'total_messages' => 0,
            'by_day' => [],
            'top_prompts' => [],
        ];

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'disease' => $disease ?: null,
            ],
            'data' => [
                'overview' => [
                    'total_patients' => $this->safeCount('users'),
                    'total_consultations' => $this->safeCountBetween('consultations', $from, $to, 'consultation_date', $rhuId),
                    'total_telemedicine_requests' => $this->safeCountBetween('telemedicine_requests', $from, $to, 'created_at', $rhuId),
                    'total_queue_tickets' => $this->safeCountBetween('queue_tickets', $from, $to, 'issued_at', $rhuId),
                    'total_chat_messages' => $this->safeCountBetween('chat_messages', $from, $to, 'created_at', $rhuId),
                    ...$this->operationsOverview($from, $to, $rhuId),
                    'barangay_cases' => $barangayCases,
                    'complaint_distribution' => $clusters,
                    'risk_summary' => $this->riskSummary($from, $to, $rhuId),
                ],
                'heatmap' => $heatmap,
                'risk' => $risk,
                'clusters' => $clusters,
                'chatbot' => $chatbot,
            ],
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $rhuId = $this->resolveRhuId($request);

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'data' => [
                'total_patients' => $this->safeCount('users'),
                'total_consultations' => $this->safeCountBetween('consultations', $from, $to, 'consultation_date', $rhuId),
                'total_telemedicine_requests' => $this->safeCountBetween('telemedicine_requests', $from, $to, 'created_at', $rhuId),
                'total_queue_tickets' => $this->safeCountBetween('queue_tickets', $from, $to, 'issued_at', $rhuId),
                'total_chat_messages' => $this->safeCountBetween('chat_messages', $from, $to, 'created_at', $rhuId),
                ...$this->operationsOverview($from, $to, $rhuId),
                'barangay_cases' => $this->barangayCases($from, $to, '', $rhuId),
                'complaint_distribution' => $this->complaintDistribution($from, $to, $rhuId),
                'risk_summary' => $this->riskSummary($from, $to, $rhuId),
            ],
        ]);
    }

    public function heatmap(Request $request, HeatmapAnalyticsService $service): JsonResponse
    {
        $validated = $request->validate([
            'disease' => ['nullable', 'string', 'max:100'],
            'range' => ['nullable', 'in:week,month'],
            'active_only' => ['nullable', 'boolean'],
            'rhu_id' => ['nullable'],
        ]);

        $disease = trim((string) ($validated['disease'] ?? ''));
        $range = $validated['range'] ?? 'week';
        $activeOnly = $request->boolean('active_only', true);
        $rhuId = $this->resolveRhuId($request);

        $points = $service->generateHeatmapData(
            $disease !== '' ? $disease : null,
            $range,
            $activeOnly,
            $rhuId
        );

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'disease' => $disease !== '' ? $disease : null,
                'range' => $range,
                'active_only' => $activeOnly,
                'rhu_id' => $rhuId,
            ],
            'data' => $points,
        ]);
    }

    public function queuePerformance(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $rhuId = $this->resolveRhuId($request);

        if (!Schema::hasTable('queue_tickets')) {
            return $this->empty('queue_performance');
        }

        $queue = $this->queueOperations($from, $to, $rhuId);

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'data' => [
                'queue_performance' => $queue['queue_performance'] ?? [],
                'queue_by_status' => $queue['queue_by_status'] ?? [],
                'queue_by_hour' => $queue['queue_by_hour'] ?? [],
                'priority_breakdown' => $queue['priority_breakdown'] ?? [],
                'priority_patient_breakdown' => $queue['priority_breakdown'] ?? [],
                'average_wait_minutes' => $queue['average_wait_minutes'] ?? 0,
                'peak_queue_hour' => $queue['peak_queue_hour'] ?? null,
            ],
        ]);
    }

    public function telemedicineSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $rows = $this->telemedicineStatusDistribution($from, $to);

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'data' => [
                'telemedicine_status_distribution' => $rows,
                'telemedicine_by_status' => $rows,
                'requests' => $rows,
                'sessions' => $rows,
                'completion_rate' => $this->completionRate($from, $to),
            ],
        ]);
    }

    public function barangayHealthProfile(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->barangayCases($from, $to),
        ]);
    }

    public function aiAccuracy(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        if (!Schema::hasTable('ai_triage_scores')) {
            return $this->empty('ai_accuracy');
        }

        $rows = DB::table('ai_triage_scores')
            ->selectRaw('recommended_urgency, COUNT(*) as total, AVG(confidence) as avg_confidence')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('recommended_urgency')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    public function registrationStats(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        if (!Schema::hasTable('event_registrations')) {
            return $this->empty('registration_stats');
        }

        $dateColumn = $this->dateColumnWithValues(
            'event_registrations',
            $from,
            $to,
            ['registered_at', 'created_at']
        );

        if (!$dateColumn) {
            return $this->empty('registration_stats');
        }

        $rows = DB::table('event_registrations')
            ->selectRaw("COALESCE(status, 'registered') as status, COUNT(*) as count")
            ->whereBetween($dateColumn, [$from, $to])
            ->groupByRaw("COALESCE(status, 'registered')")
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => $this->chartRow(
                $this->displayLabel((string) $row->status),
                (int) $row->count,
                ['status' => (string) $row->status]
            ))
            ->values();

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'data' => $rows,
        ]);
    }

    public function chatbotUsage(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        $messagesTable = Schema::hasTable('chat_messages')
            ? 'chat_messages'
            : (Schema::hasTable('chat_logs') ? 'chat_logs' : null);

        if (!$messagesTable) {
            return $this->empty('chatbot_usage');
        }

        $roleColumn = Schema::hasColumn($messagesTable, 'role')
            ? 'role'
            : (Schema::hasColumn($messagesTable, 'sender') ? 'sender' : null);

        $contentColumn = Schema::hasColumn($messagesTable, 'content')
            ? 'content'
            : (Schema::hasColumn($messagesTable, 'message') ? 'message' : null);

        $query = DB::table($messagesTable)->whereBetween('created_at', [$from, $to]);

        $byDay = (clone $query)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        $topPrompts = $contentColumn
            ? (clone $query)
                ->selectRaw("LOWER(SUBSTRING({$contentColumn}, 1, 80)) as prompt, COUNT(*) as total")
                ->when($roleColumn, function ($q) use ($roleColumn) {
                    $q->whereIn($roleColumn, ['user', 'patient', 'resident']);
                })
                ->groupByRaw("LOWER(SUBSTRING({$contentColumn}, 1, 80))")
                ->orderByDesc('total')
                ->limit(10)
                ->get()
            : collect();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_messages' => (int) (clone $query)->count(),
                'by_day' => $byDay,
                'top_prompts' => $topPrompts,
            ],
        ]);
    }

    public function queueHeatmap(Request $request, HeatmapAnalyticsService $service): JsonResponse
    {
        $validated = $request->validate([
            'disease' => ['nullable', 'string', 'max:100'],
            'range' => ['nullable', 'in:week,month'],
            'active_only' => ['nullable', 'boolean'],
            'rhu_id' => ['nullable'],
        ]);

        $disease = trim((string) ($validated['disease'] ?? ''));
        $range = $validated['range'] ?? 'week';
        $activeOnly = $request->boolean('active_only', true);
        $rhuId = $this->resolveRhuId($request);

        $points = $service->generateHeatmapData(
            $disease !== '' ? $disease : null,
            $range,
            $activeOnly,
            $rhuId
        );

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'disease' => $disease !== '' ? $disease : null,
                'range' => $range,
                'active_only' => $activeOnly,
                'rhu_id' => $rhuId,
            ],
            'data' => $points,
        ]);
    }

    public function barangayRisk(Request $request, HeatmapAnalyticsService $service): JsonResponse
    {
        $validated = $request->validate([
            'disease' => ['nullable', 'string', 'max:100'],
            'range' => ['nullable', 'in:week,month'],
            'active_only' => ['nullable', 'boolean'],
            'rhu_id' => ['nullable'],
        ]);

        $disease = trim((string) ($validated['disease'] ?? ''));
        $range = $validated['range'] ?? 'week';
        $activeOnly = $request->boolean('active_only', true);
        $rhuId = $this->resolveRhuId($request);

        $items = collect(
            $service->generateHeatmapData(
                $disease !== '' ? $disease : null,
                $range,
                $activeOnly,
                $rhuId
            )
        )
            ->sortByDesc('risk_score')
            ->values();

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'disease' => $disease !== '' ? $disease : null,
                'range' => $range,
                'active_only' => $activeOnly,
                'rhu_id' => $rhuId,
            ],
            'summary' => $items->countBy('risk_level'),
            'data' => $items,
        ]);
    }

    public function queueDensity(Request $request): JsonResponse
    {
        if (!Schema::hasTable('queue_tickets')) {
            return $this->empty('queue_density');
        }

        [$from, $to] = $this->dateRange($request);
        $rhuId = $this->resolveRhuId($request);

        $dateColumn = $this->dateColumnWithValues(
            'queue_tickets',
            $from,
            $to,
            ['issued_at', 'created_at']
        ) ?? 'created_at';

        $rows = DB::table('queue_tickets')
            ->selectRaw("COALESCE(service_type, 'unspecified') as service_type, status, COUNT(*) as total")
            ->whereDate($dateColumn, today())
            ->when(Schema::hasColumn('queue_tickets', 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'rhu_id'))
            ->groupByRaw("COALESCE(service_type, 'unspecified'), status")
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    public function diseaseClusters(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->complaintDistribution($from, $to),
        ]);
    }

    public function outbreakAlerts(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $threshold = (int) $request->query('threshold', 5);

        $alerts = collect($this->barangayCases($from, $to))
            ->filter(fn ($row) => (int) ($row['total_cases'] ?? 0) >= $threshold)
            ->map(fn ($row) => [
                'id' => Str::slug(($row['barangay'] ?? 'unspecified') . '-' . ($row['top_complaint'] ?? 'case')),
                'barangay' => $row['barangay'] ?? 'Unspecified',
                'case_type' => $row['top_complaint'] ?? 'Unspecified',
                'total_cases' => (int) ($row['total_cases'] ?? 0),
                'queue_density' => (int) ($row['queue_density'] ?? 0),
                'status' => 'active',
                'message' => 'High number of similar complaints recorded in ' . ($row['barangay'] ?? 'Unspecified') . '.',
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $alerts,
        ]);
    }

    public function resolveAlert(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Alert marked as resolved for monitoring logs.',
            'data' => [
                'id' => $id,
                'resolved_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function priorityDashboard(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);
        $rhuId = $this->resolveRhuId($request);

        if (!Schema::hasTable('queue_tickets')) {
            return $this->empty('priority_dashboard');
        }

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'data' => $this->queuePriorityBreakdown($from, $to, $rhuId),
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

    public function diagnosisItrSummary(
        Request $request,
        DiagnosisItrReportService $diagnosisItr
    ): JsonResponse {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'rhu_id' => ['nullable'],
            'barangay_id' => ['nullable'],
            'diagnosis' => ['nullable', 'string', 'max:150'],
            'disease' => ['nullable', 'string', 'max:150'],
        ]);

        $filters = [
            'date_from' => $validated['date_from'] ?? $validated['from'] ?? null,
            'date_to' => $validated['date_to'] ?? $validated['to'] ?? null,
            'rhu_id' => $validated['rhu_id'] ?? null,
            'barangay_id' => $validated['barangay_id'] ?? null,
            'diagnosis' => trim((string) ($validated['diagnosis'] ?? $validated['disease'] ?? '')) ?: null,
        ];

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => $filters,
            'data' => $diagnosisItr->summary($filters, $request->user()),
        ]);
    }

    public function heatmapDiagnosisItrSignals(
        Request $request,
        DiagnosisItrReportService $diagnosisItr
    ): JsonResponse {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'rhu_id' => ['nullable'],
            'barangay_id' => ['nullable'],
            'diagnosis' => ['nullable', 'string', 'max:150'],
            'disease' => ['nullable', 'string', 'max:150'],
        ]);

        $filters = [
            'date_from' => $validated['date_from'] ?? $validated['from'] ?? null,
            'date_to' => $validated['date_to'] ?? $validated['to'] ?? null,
            'rhu_id' => $validated['rhu_id'] ?? null,
            'barangay_id' => $validated['barangay_id'] ?? null,
            'diagnosis' => trim((string) ($validated['diagnosis'] ?? $validated['disease'] ?? '')) ?: null,
        ];

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => $filters,
            'data' => $diagnosisItr->heatmapSignals($filters, $request->user())->values(),
        ]);
    }

    /**
     * Resolve the facility RHU a request may operate on. Global staff
     * (super_admin / MHO) may pass ?rhu_id= to focus one facility or omit it to
     * see all; every other role is HARD-LOCKED to their own RHU regardless of
     * the param. Returns null only for global staff who asked for "all".
     */
    private function resolveRhuId(Request $request): ?int
    {
        $requested = $request->query('rhu_id');
        $requested = ($requested === null || $requested === '' || $requested === 'all')
            ? null
            : (int) $requested;

        return Rhu::filterRhuId($request->user(), $requested);
    }

    /**
     * Scope a query to a facility RHU. null => all RHUs (no filter). RHU 1 also
     * owns legacy / null / unmapped rhu_id rows from the pre-RHU2 era, matching
     * how AppointmentController resolves the default facility.
     */
    private function scopeRhu($query, ?int $rhuId, string $column = 'rhu_id')
    {
        if ($rhuId === null) {
            return $query;
        }

        if ($rhuId === Rhu::DEFAULT_ID) {
            return $query->where(function ($q) use ($column) {
                $q->where($column, Rhu::DEFAULT_ID)
                    ->orWhereNull($column)
                    ->orWhereNotIn($column, Rhu::IDS);
            });
        }

        return $query->where($column, $rhuId);
    }

    private function operationsOverview(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        $attendance = $this->attendanceOverview($from, $to, $rhuId);
        $queue = $this->queueOperations($from, $to, $rhuId);
        $appointments = $this->statusBreakdown(
            'appointments',
            $from,
            $to,
            ['appointment_date', 'scheduled_at', 'approved_at', 'created_at'],
            'appointment',
            $rhuId
        );
        $telemedicine = $this->telemedicineStatusDistribution($from, $to, $rhuId);
        $programs = $this->programParticipation($from, $to);
        $aiTriage = $this->aiTriageDistribution($from, $to, $rhuId);

        return [
            ...$attendance,
            ...$queue,

            'appointments_by_status' => $appointments,
            'appointment_status_distribution' => $appointments,
            'appointmentStatusDistribution' => $appointments,

            'telemedicine_by_status' => $telemedicine,
            'telemedicine_status_distribution' => $telemedicine,
            'telemedicineStatusDistribution' => $telemedicine,

            'program_participation' => $programs,
            'programParticipation' => $programs,
            'program_participation_by_event' => $programs,
            'program_participation_by_barangay' => $programs,

            'ai_triage_distribution' => $aiTriage,
            'aiTriageDistribution' => $aiTriage,
            'ai_triage_staff_disclaimer' => AiTriageService::STAFF_DISCLAIMER,

            'priority_patient_breakdown' => $queue['priority_breakdown'] ?? [],
            'priorityPatientBreakdown' => $queue['priority_breakdown'] ?? [],

            'pending_appointments' => $this->statusTotal($appointments, 'pending'),
            'approved_appointments' => $this->statusTotal($appointments, 'approved'),
            'completed_appointments' => $this->statusTotal($appointments, 'completed'),
            'cancelled_appointments' => $this->statusTotal($appointments, 'cancelled'),
            'rejected_appointments' => $this->statusTotal($appointments, 'rejected'),
            'no_show_appointments' => $this->statusTotal($appointments, 'no_show'),

            'pending_telemedicine_requests' => $this->statusTotal($telemedicine, 'pending'),
            'scheduled_telemedicine_sessions' => $this->statusTotal($telemedicine, 'scheduled'),
            'completed_telemedicine_sessions' => $this->statusTotal($telemedicine, 'completed'),
            'cancelled_telemedicine_requests' => $this->statusTotal($telemedicine, 'cancelled'),
            'rejected_telemedicine_requests' => $this->statusTotal($telemedicine, 'rejected'),
        ];
    }

    private function attendanceOverview(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        $source = 'completed_consultations';
        $timestamps = $this->consultationAttendanceTimestamps($from, $to, $rhuId);

        if ($timestamps->isEmpty()) {
            $source = 'queue_tickets';
            $timestamps = $this->tableTimestamps('queue_tickets', $from, $to, 'issued_at', [], $rhuId);
        }

        if ($timestamps->isEmpty()) {
            $source = 'completed_appointments';
            $timestamps = $this->tableTimestamps(
                'appointments',
                $from,
                $to,
                'appointment_date',
                ['completed', 'done', 'served'],
                $rhuId
            );
        }

        $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $byDay = collect($dayOrder)->mapWithKeys(fn ($day) => [$day => 0])->all();
        $byDate = [];

        foreach ($timestamps as $raw) {
            if (!$raw) {
                continue;
            }

            try {
                $date = Carbon::parse($raw);
            } catch (\Throwable) {
                continue;
            }

            $day = $date->format('l');
            $dateKey = $date->toDateString();

            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
            $byDate[$dateKey] = ($byDate[$dateKey] ?? 0) + 1;
        }

        ksort($byDate);

        $total = array_sum($byDay);
        $activeDays = count(array_filter($byDate, fn ($count) => $count > 0));
        $average = $activeDays > 0 ? round($total / $activeDays, 1) : 0;

        $peakDay = collect($byDay)->sortDesc()->keys()->first();
        $slowestDay = collect($byDay)->sort()->keys()->first();

        return [
            'attendance_source' => $source,
            'total_patient_visits' => $total,
            'active_visit_days' => $activeDays,
            'average_patients_per_day' => $average,
            'peak_patient_day' => [
                'label' => $peakDay,
                'total' => (int) ($byDay[$peakDay] ?? 0),
            ],
            'slowest_patient_day' => [
                'label' => $slowestDay,
                'total' => (int) ($byDay[$slowestDay] ?? 0),
            ],
            'attendance_by_day_of_week' => collect($byDay)
                ->map(fn ($total, $day) => [
                    'day' => $day,
                    'label' => substr($day, 0, 3),
                    'total' => (int) $total,
                ])
                ->values()
                ->all(),
            'attendance_by_date' => collect($byDate)
                ->map(fn ($total, $date) => [
                    'date' => $date,
                    'label' => $date,
                    'total' => (int) $total,
                ])
                ->values()
                ->all(),
        ];
    }

    private function consultationAttendanceTimestamps(Carbon $from, Carbon $to, ?int $rhuId = null)
    {
        if (!Schema::hasTable('consultations')) {
            return collect();
        }

        $dateColumn = Schema::hasColumn('consultations', 'completed_at')
            ? 'completed_at'
            : (Schema::hasColumn('consultations', 'consultation_date') ? 'consultation_date' : 'created_at');

        $query = DB::table('consultations as c')
            ->whereBetween("c.{$dateColumn}", [$from, $to])
            ->when(Schema::hasColumn('consultations', 'status'), function ($q) {
                $q->whereIn('c.status', ['completed', 'done', 'served']);
            });

        if (
            Schema::hasTable('appointments') &&
            Schema::hasColumn('consultations', 'appointment_id') &&
            Schema::hasColumn('appointments', 'rhu_id')
        ) {
            $query->leftJoin('appointments as a', 'a.id', '=', 'c.appointment_id');
            $this->scopeRhu($query, $rhuId, 'a.rhu_id');
        }

        return $query->pluck("c.{$dateColumn}");
    }

    private function tableTimestamps(
        string $table,
        Carbon $from,
        Carbon $to,
        string $preferredColumn = 'created_at',
        array $statuses = [],
        ?int $rhuId = null
    ) {
        if (!Schema::hasTable($table)) {
            return collect();
        }

        $dateColumn = $this->dateColumnWithValues(
            $table,
            $from,
            $to,
            [$preferredColumn, 'created_at']
        );

        if (!$dateColumn) {
            return collect();
        }

        return DB::table($table)
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn($table, 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'rhu_id'))
            ->when($statuses && Schema::hasColumn($table, 'status'), fn ($q) => $q->whereIn('status', $statuses))
            ->pluck($dateColumn);
    }

    private function queueOperations(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        $empty = [
            'queue_by_hour' => [],
            'queueByHour' => [],
            'queue_by_status' => [],
            'queue_performance' => [],
            'queuePerformance' => [],
            'priority_breakdown' => $this->emptyPriorityCategories(),
            'priority_patient_breakdown' => $this->emptyPriorityCategories(),
            'average_wait_minutes' => 0,
            'peak_queue_hour' => null,
            'waiting_queue' => 0,
            'currently_serving' => 0,
            'served_queue' => 0,
            'skipped_queue' => 0,
            'cancelled_queue' => 0,
            'priority_queue' => 0,
            'regular_queue' => 0,
        ];

        if (!Schema::hasTable('queue_tickets')) {
            return $empty;
        }

        $dateColumn = $this->dateColumnWithValues(
            'queue_tickets',
            $from,
            $to,
            ['issued_at', 'created_at']
        );

        if (!$dateColumn) {
            return $empty;
        }

        $base = DB::table('queue_tickets')
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn('queue_tickets', 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'rhu_id'))
            ->when(Schema::hasColumn('queue_tickets', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'));

        $timestamps = (clone $base)
            ->whereNotNull($dateColumn)
            ->pluck($dateColumn);

        $byHour = array_fill(0, 24, 0);

        foreach ($timestamps as $raw) {
            try {
                $hour = (int) Carbon::parse($raw)->format('G');
                $byHour[$hour] += 1;
            } catch (\Throwable) {
                continue;
            }
        }

        $queueByHour = collect($byHour)
            ->map(fn ($count, $hour) => $this->chartRow(
                Carbon::createFromTime((int) $hour)->format('g A'),
                (int) $count,
                ['hour' => (int) $hour]
            ))
            ->filter(fn ($row) => $row['count'] > 0)
            ->values()
            ->all();

        $queueByStatus = $this->queuePerformanceFromCounters($from, $to, $rhuId);

        if (array_sum(array_column($queueByStatus, 'count')) <= 0) {
            $queueByStatus = $this->queuePerformanceFromTickets($base);
        }

        $priorityBreakdown = $this->queuePriorityBreakdown($from, $to, $rhuId);
        $peak = collect($queueByHour)->sortByDesc('count')->first();

        $waiting = $this->statusTotal($queueByStatus, 'waiting');
        $serving = $this->statusTotal($queueByStatus, 'serving');
        $served = $this->statusTotal($queueByStatus, 'served');
        $skipped = $this->statusTotal($queueByStatus, 'skipped');
        $cancelled = $this->statusTotal($queueByStatus, 'cancelled');
        $priorityQueue = collect($priorityBreakdown)
            ->reject(fn ($row) => ($row['status'] ?? '') === 'regular')
            ->sum('count');
        $regularQueue = $this->statusTotal($priorityBreakdown, 'regular');

        return [
            'queue_by_hour' => $queueByHour,
            'queueByHour' => $queueByHour,

            'queue_by_status' => $queueByStatus,
            'queue_performance' => $queueByStatus,
            'queuePerformance' => $queueByStatus,

            'priority_breakdown' => $priorityBreakdown,
            'priority_patient_breakdown' => $priorityBreakdown,
            'priorityPatientBreakdown' => $priorityBreakdown,

            'average_wait_minutes' => Schema::hasColumn('queue_tickets', 'wait_time_minutes')
                ? round((float) (clone $base)->avg('wait_time_minutes'), 1)
                : 0,
            'peak_queue_hour' => $peak,

            'waiting_queue' => $waiting,
            'currently_serving' => $serving,
            'served_queue' => $served,
            'skipped_queue' => $skipped,
            'cancelled_queue' => $cancelled,
            'priority_queue' => (int) $priorityQueue,
            'regular_queue' => (int) $regularQueue,
        ];
    }

    private function statusBreakdown(
        string $table,
        Carbon $from,
        Carbon $to,
        array|string $preferredColumns = 'created_at',
        string $statusType = 'generic',
        ?int $rhuId = null
    ): array {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'status')) {
            return [];
        }

        $preferred = is_array($preferredColumns) ? $preferredColumns : [$preferredColumns, 'created_at'];
        $dateColumn = $this->dateColumnWithValues($table, $from, $to, $preferred);

        if (!$dateColumn) {
            return [];
        }

        $rawRows = DB::table($table)
            ->selectRaw("COALESCE(status, 'unknown') as raw_status, COUNT(*) as count")
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn($table, 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'rhu_id'))
            ->groupByRaw("COALESCE(status, 'unknown')")
            ->get();

        $counts = [];

        foreach ($rawRows as $row) {
            [$status, $label] = $this->normalizeStatus((string) $row->raw_status, $statusType);
            $counts[$status] = [
                'status' => $status,
                'label' => $label,
                'count' => ($counts[$status]['count'] ?? 0) + (int) $row->count,
                'raw_statuses' => array_values(array_unique([
                    ...($counts[$status]['raw_statuses'] ?? []),
                    (string) $row->raw_status,
                ])),
            ];
        }

        return collect($counts)
            ->map(fn ($row) => $this->chartRow(
                $row['label'],
                $row['count'],
                [
                    'status' => $row['status'],
                    'raw_statuses' => $row['raw_statuses'],
                ]
            ))
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function telemedicineStatusDistribution(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        $requests = $this->statusBreakdown(
            'telemedicine_requests',
            $from,
            $to,
            ['created_at', 'screened_at', 'cancelled_at'],
            'telemedicine',
            $rhuId
        );

        if (array_sum(array_column($requests, 'count')) > 0) {
            return $requests;
        }

        return $this->statusBreakdown(
            'telemedicine_sessions',
            $from,
            $to,
            ['scheduled_date', 'started_at', 'ended_at', 'created_at'],
            'telemedicine',
            $rhuId
        );
    }

    private function programParticipation(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('event_registrations')) {
            return [];
        }

        $dateColumn = $this->dateColumnWithValues(
            'event_registrations',
            $from,
            $to,
            ['registered_at', 'created_at']
        );

        if (!$dateColumn) {
            return [];
        }

        $query = DB::table('event_registrations as er')
            ->whereBetween("er.{$dateColumn}", [$from, $to]);

        $eventLabelExpr = "CONCAT('Event #', er.event_id)";
        $eventTypeExpr = "'event'";
        $categoryExpr = "'Program / Event'";
        $hasEvents = Schema::hasTable('events');

        if ($hasEvents) {
            $query->leftJoin('events as e', 'e.id', '=', 'er.event_id');
            $eventLabelExpr = "COALESCE(e.title, CONCAT('Event #', er.event_id))";
            $eventTypeExpr = Schema::hasColumn('events', 'event_type')
                ? "COALESCE(e.event_type, 'event')"
                : "'event'";
            $categoryExpr = Schema::hasColumn('events', 'category')
                ? "COALESCE(e.category, 'Program / Event')"
                : "'Program / Event'";
        }

        $barangayExpr = "'Unspecified'";

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('event_registrations', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id') &&
            Schema::hasTable('barangays')
        ) {
            $query->leftJoin('resident_profiles as rp', 'rp.user_id', '=', 'er.user_id')
                ->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id');

            $barangayExpr = "COALESCE(b.name, 'Unspecified')";
        }

        $rows = $query
            ->selectRaw("{$eventLabelExpr} as event_title")
            ->selectRaw("{$eventTypeExpr} as event_type")
            ->selectRaw("{$categoryExpr} as category")
            ->selectRaw("{$barangayExpr} as barangay")
            ->selectRaw("COUNT(*) as count")
            ->when(Schema::hasColumn('event_registrations', 'status'), function ($q) {
                $q->whereIn('er.status', ['registered', 'attended', 'completed']);
            })
            ->groupByRaw("{$eventLabelExpr}, {$eventTypeExpr}, {$categoryExpr}, {$barangayExpr}")
            ->orderByDesc('count')
            ->limit(200)
            ->get();

        return $rows
            ->groupBy('event_title')
            ->map(function ($group, $title) {
                $count = (int) $group->sum('count');
                $barangays = $group
                    ->pluck('barangay')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $barangaySummary = count($barangays) > 0
                    ? implode(', ', array_slice($barangays, 0, 5))
                    : 'Unspecified';

                if (count($barangays) > 5) {
                    $barangaySummary .= ' +' . (count($barangays) - 5) . ' more';
                }

                $first = $group->first();

                return $this->chartRow(
                    (string) $title,
                    $count,
                    [
                        'event' => (string) $title,
                        'program' => (string) $title,
                        'title' => (string) $title,
                        'event_type' => (string) ($first->event_type ?? 'event'),
                        'category' => (string) ($first->category ?? 'Program / Event'),
                        'barangay' => $barangaySummary,
                        'barangays' => $barangays,
                        'helper' => 'Barangay: ' . $barangaySummary,
                    ]
                );
            })
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    private function aiTriageDistribution(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        $counts = $this->emptyTriageCounts();

        if (Schema::hasTable('ai_triage_scores')) {
            $levelColumn = Schema::hasColumn('ai_triage_scores', 'triage_level')
                ? 'triage_level'
                : (Schema::hasColumn('ai_triage_scores', 'recommended_urgency') ? 'recommended_urgency' : null);

            if ($levelColumn) {
                $stored = DB::table('ai_triage_scores')
                    ->selectRaw("COALESCE({$levelColumn}, 'low') as triage_level, COUNT(*) as count")
                    ->whereBetween('created_at', [$from, $to])
                    ->groupByRaw("COALESCE({$levelColumn}, 'low')")
                    ->get();

                foreach ($stored as $row) {
                    $level = $this->triageLevel((string) $row->triage_level);
                    $counts[$level] += (int) $row->count;
                }

                if (array_sum($counts) > 0) {
                    return $this->triageCountsToRows($counts);
                }
            }
        }

        if (!Schema::hasTable('queue_tickets')) {
            return $this->triageCountsToRows($counts);
        }

        $dateColumn = $this->dateColumnWithValues(
            'queue_tickets',
            $from,
            $to,
            ['issued_at', 'created_at']
        );

        if (!$dateColumn) {
            return $this->triageCountsToRows($counts);
        }

        $query = DB::table('queue_tickets as q')
            ->whereBetween("q.{$dateColumn}", [$from, $to])
            ->when(Schema::hasColumn('queue_tickets', 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'q.rhu_id'))
            ->when(Schema::hasColumn('queue_tickets', 'deleted_at'), fn ($q) => $q->whereNull('q.deleted_at'));

        $hasProfileJoin = Schema::hasTable('resident_profiles')
            && Schema::hasColumn('queue_tickets', 'resident_profile_id');

        if ($hasProfileJoin) {
            $query->leftJoin('resident_profiles as rp', 'rp.id', '=', 'q.resident_profile_id');
        }

        $select = [
            'q.id',
        ];

        foreach ([
            'priority_score',
            'priority_category',
            'is_emergency',
            'is_senior',
            'is_pwd',
            'is_pregnant',
            'is_pediatric',
            'notes',
            'service_type',
        ] as $column) {
            if (Schema::hasColumn('queue_tickets', $column)) {
                $select[] = "q.{$column}";
            }
        }

        if ($hasProfileJoin) {
            foreach ([
                'birth_date',
                'birthdate',
                'date_of_birth',
                'is_senior',
                'is_pwd',
                'is_pregnant',
            ] as $column) {
                if (Schema::hasColumn('resident_profiles', $column)) {
                    $select[] = "rp.{$column} as rp_{$column}";
                }
            }
        }

        $service = app(AiTriageService::class);

        foreach ($query->get($select) as $row) {
            $age = $this->ageFromRow($row);

            $payload = [
                'age' => $age,
                'chief_complaint' => (string) ($row->notes ?? ''),
                'complaint' => (string) ($row->notes ?? ''),
                'service_type' => (string) ($row->service_type ?? ''),
                'is_senior' => (bool) ($row->is_senior ?? $row->rp_is_senior ?? ($age !== null && $age >= 60)),
                'is_pwd' => (bool) ($row->is_pwd ?? $row->rp_is_pwd ?? false),
                'is_pregnant' => (bool) ($row->is_pregnant ?? $row->rp_is_pregnant ?? false),
                'is_pediatric' => (bool) ($row->is_pediatric ?? ($age !== null && $age <= 12)),
                'is_emergency' => (bool) ($row->is_emergency ?? false),
            ];

            $result = $service->scorePayload($payload);
            $level = $this->triageLevel((string) ($result['triage_level'] ?? 'low'));
            $counts[$level] += 1;
        }

        return $this->triageCountsToRows($counts);
    }

    private function queuePerformanceFromCounters(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        $empty = collect([
            'waiting' => 'Waiting',
            'serving' => 'Serving',
            'served' => 'Served',
            'skipped' => 'Skipped',
            'cancelled' => 'Cancelled',
        ])
            ->map(fn ($label, $status) => $this->chartRow($label, 0, ['status' => $status]))
            ->values()
            ->all();

        if (!Schema::hasTable('queue_counters')) {
            return $empty;
        }

        $dateColumn = $this->dateColumnWithValues(
            'queue_counters',
            $from,
            $to,
            ['queue_date', 'created_at']
        );

        if (!$dateColumn) {
            return $empty;
        }

        $rows = DB::table('queue_counters')
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn('queue_counters', 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'rhu_id'))
            ->get();

        if ($rows->isEmpty()) {
            return $empty;
        }

        $totalIssued = (int) $rows->sum(fn ($row) => (int) ($row->last_issued_number ?? 0));
        $currentServing = (int) $rows
            ->filter(fn ($row) => !empty($row->current_serving_number) && (bool) ($row->is_active ?? true))
            ->count();
        $servedEstimate = (int) $rows->sum(fn ($row) => (int) ($row->current_serving_number ?? 0));
        $waitingEstimate = max(0, $totalIssued - $servedEstimate - $currentServing);

        return [
            $this->chartRow('Waiting', $waitingEstimate, ['status' => 'waiting']),
            $this->chartRow('Serving', $currentServing, ['status' => 'serving']),
            $this->chartRow('Served', $servedEstimate, ['status' => 'served']),
            $this->chartRow('Skipped', 0, ['status' => 'skipped']),
            $this->chartRow('Cancelled', 0, ['status' => 'cancelled']),
        ];
    }

    private function queuePerformanceFromTickets($baseQuery): array
    {
        $rawRows = (clone $baseQuery)
            ->selectRaw("COALESCE(status, 'waiting') as raw_status, COUNT(*) as count")
            ->groupByRaw("COALESCE(status, 'waiting')")
            ->get();

        $counts = [
            'waiting' => 0,
            'serving' => 0,
            'served' => 0,
            'skipped' => 0,
            'cancelled' => 0,
        ];

        foreach ($rawRows as $row) {
            [$status] = $this->normalizeStatus((string) $row->raw_status, 'queue');
            if (array_key_exists($status, $counts)) {
                $counts[$status] += (int) $row->count;
            }
        }

        return collect($counts)
            ->map(fn ($count, $status) => $this->chartRow(
                $this->normalizeStatus($status, 'queue')[1],
                (int) $count,
                ['status' => $status]
            ))
            ->values()
            ->all();
    }

    private function queuePriorityBreakdown(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        $counts = [
            'senior_citizen' => 0,
            'pwd' => 0,
            'pregnant' => 0,
            'child' => 0,
            'urgent_complaint' => 0,
            'regular' => 0,
        ];

        if (!Schema::hasTable('queue_tickets')) {
            return $this->priorityCountsToRows($counts);
        }

        $dateColumn = $this->dateColumnWithValues(
            'queue_tickets',
            $from,
            $to,
            ['issued_at', 'created_at']
        );

        if (!$dateColumn) {
            return $this->priorityCountsToRows($counts);
        }

        $query = DB::table('queue_tickets as q')
            ->whereBetween("q.{$dateColumn}", [$from, $to])
            ->when(Schema::hasColumn('queue_tickets', 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'q.rhu_id'))
            ->when(Schema::hasColumn('queue_tickets', 'deleted_at'), fn ($q) => $q->whereNull('q.deleted_at'));

        $hasProfileJoin = Schema::hasTable('resident_profiles')
            && Schema::hasColumn('queue_tickets', 'resident_profile_id');

        if ($hasProfileJoin) {
            $query->leftJoin('resident_profiles as rp', 'rp.id', '=', 'q.resident_profile_id');
        }

        $select = ['q.id'];

        foreach ([
            'priority_score',
            'priority_category',
            'is_emergency',
            'is_senior',
            'is_pwd',
            'is_pregnant',
            'is_pediatric',
            'notes',
        ] as $column) {
            if (Schema::hasColumn('queue_tickets', $column)) {
                $select[] = "q.{$column}";
            }
        }

        if ($hasProfileJoin) {
            foreach ([
                'birth_date',
                'birthdate',
                'date_of_birth',
                'is_senior',
                'is_pwd',
                'is_pregnant',
            ] as $column) {
                if (Schema::hasColumn('resident_profiles', $column)) {
                    $select[] = "rp.{$column} as rp_{$column}";
                }
            }
        }

        foreach ($query->get($select) as $row) {
            $category = Str::lower((string) ($row->priority_category ?? ''));
            $score = (int) ($row->priority_score ?? 0);
            $notes = Str::lower((string) ($row->notes ?? ''));
            $age = $this->ageFromRow($row);

            $isSenior = (bool) ($row->is_senior ?? false)
                || (bool) ($row->rp_is_senior ?? false)
                || ($age !== null && $age >= 60)
                || $category === 'senior_citizen';

            $isPwd = (bool) ($row->is_pwd ?? false)
                || (bool) ($row->rp_is_pwd ?? false)
                || $category === 'pwd';

            $isPregnant = (bool) ($row->is_pregnant ?? false)
                || (bool) ($row->rp_is_pregnant ?? false)
                || $category === 'pregnant';

            $isChild = (bool) ($row->is_pediatric ?? false)
                || ($age !== null && $age <= 12)
                || in_array($category, ['pediatric', 'child'], true);

            $isUrgent = (bool) ($row->is_emergency ?? false)
                || $score >= 80
                || in_array($category, ['emergency', 'urgent', 'critical'], true)
                || $this->containsUrgentComplaint($notes);

            if ($isUrgent) {
                $counts['urgent_complaint']++;
            } elseif ($isPregnant) {
                $counts['pregnant']++;
            } elseif ($isSenior) {
                $counts['senior_citizen']++;
            } elseif ($isPwd) {
                $counts['pwd']++;
            } elseif ($isChild) {
                $counts['child']++;
            } else {
                $counts['regular']++;
            }
        }

        return $this->priorityCountsToRows($counts);
    }

    private function priorityCountsToRows(array $counts): array
    {
        $labels = [
            'senior_citizen' => 'Senior Citizen',
            'pwd' => 'PWD',
            'pregnant' => 'Pregnant',
            'child' => 'Child',
            'urgent_complaint' => 'Urgent Complaint',
            'regular' => 'Regular',
        ];

        return collect($labels)
            ->map(fn ($label, $status) => $this->chartRow(
                $label,
                (int) ($counts[$status] ?? 0),
                ['status' => $status]
            ))
            ->values()
            ->all();
    }

    private function emptyPriorityCategories(): array
    {
        return $this->priorityCountsToRows([
            'senior_citizen' => 0,
            'pwd' => 0,
            'pregnant' => 0,
            'child' => 0,
            'urgent_complaint' => 0,
            'regular' => 0,
        ]);
    }

    private function emptyTriageCounts(): array
    {
        return [
            'urgent' => 0,
            'high' => 0,
            'moderate' => 0,
            'low' => 0,
        ];
    }

    private function triageCountsToRows(array $counts): array
    {
        $total = array_sum($counts);

        return collect(['urgent', 'high', 'moderate', 'low'])
            ->map(fn ($level) => $this->chartRow(
                $this->displayLabel($level),
                (int) ($counts[$level] ?? 0),
                [
                    'triage_level' => $level,
                    'status' => $level,
                    'percentage' => $total > 0 ? round(((int) ($counts[$level] ?? 0) / $total) * 100, 1) : 0,
                    'staff_disclaimer' => AiTriageService::STAFF_DISCLAIMER,
                ]
            ))
            ->values()
            ->all();
    }

    private function chartRow(string $label, int|float $count, array $extra = []): array
    {
        $safeCount = (int) $count;

        return [
            'label' => $label,
            'count' => $safeCount,
            'total' => $safeCount,
            'value' => $safeCount,
            ...$extra,
        ];
    }

    private function normalizeStatus(string $value, string $type = 'generic'): array
    {
        $normalized = Str::lower(trim(str_replace('-', '_', $value)));

        if ($type === 'queue') {
            return match ($normalized) {
                'pending', 'waiting' => ['waiting', 'Waiting'],
                'called', 'serving', 'in_service', 'in service' => ['serving', 'Serving'],
                'served', 'completed', 'complete', 'done' => ['served', 'Served'],
                'skipped' => ['skipped', 'Skipped'],
                'cancelled', 'canceled' => ['cancelled', 'Cancelled'],
                default => ['waiting', 'Waiting'],
            };
        }

        if ($type === 'appointment') {
            return match ($normalized) {
                'pending' => ['pending', 'Pending'],
                'approved', 'confirmed', 'scheduled' => ['approved', 'Approved'],
                'completed', 'complete', 'done', 'served' => ['completed', 'Completed'],
                'cancelled', 'canceled' => ['cancelled', 'Cancelled'],
                'rejected', 'declined' => ['rejected', 'Rejected'],
                'no_show', 'noshow', 'missed' => ['no_show', 'No-show'],
                default => [$normalized ?: 'pending', $this->displayLabel($normalized ?: 'pending')],
            };
        }

        if ($type === 'telemedicine') {
            return match ($normalized) {
                'pending' => ['pending', 'Pending'],
                'screened', 'scheduled', 'waiting', 'active', 'paused' => ['scheduled', 'Scheduled'],
                'completed', 'complete', 'done', 'ended' => ['completed', 'Completed'],
                'cancelled', 'canceled', 'no_show', 'noshow' => ['cancelled', 'Cancelled'],
                'rejected', 'declined' => ['rejected', 'Rejected'],
                default => [$normalized ?: 'pending', $this->displayLabel($normalized ?: 'pending')],
            };
        }

        return [$normalized ?: 'unknown', $this->displayLabel($normalized ?: 'unknown')];
    }

    private function ageFromRow(object $row): ?int
    {
        foreach (['rp_birth_date', 'rp_birthdate', 'rp_date_of_birth', 'birth_date', 'birthdate', 'date_of_birth'] as $field) {
            if (empty($row->{$field})) {
                continue;
            }

            try {
                return Carbon::parse($row->{$field})->age;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function dateColumnWithValues(
        string $table,
        Carbon $from,
        Carbon $to,
        array $preferredColumns
    ): ?string {
        if (!Schema::hasTable($table)) {
            return null;
        }

        foreach (array_values(array_unique($preferredColumns)) as $column) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }

            $count = (int) DB::table($table)
                ->whereNotNull($column)
                ->whereBetween($column, [$from, $to])
                ->count();

            if ($count > 0) {
                return $column;
            }
        }

        foreach (array_values(array_unique($preferredColumns)) as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return Schema::hasColumn($table, 'created_at') ? 'created_at' : null;
    }

    private function containsUrgentComplaint(string $notes): bool
    {
        return Str::contains($notes, [
            'chest pain',
            'pananakit ng dibdib',
            'difficulty breathing',
            'hirap huminga',
            'severe bleeding',
            'seizure',
            'fainting',
            'loss of consciousness',
            'nahimatay',
            'pregnancy emergency',
            'severe dehydration',
            'very high fever',
        ]);
    }

    private function containsModerateComplaint(string $notes): bool
    {
        return Str::contains($notes, [
            'fever',
            'lagnat',
            'cough',
            'ubo',
            'vomiting',
            'pagsusuka',
            'diarrhea',
            'pagtatae',
            'dizziness',
            'hilo',
            'wound',
            'sugat',
            'follow-up',
        ]);
    }

    private function statusTotal(array $rows, string $status): int
    {
        $needle = Str::lower(str_replace('-', '_', $status));

        return (int) collect($rows)
            ->filter(function ($row) use ($needle) {
                $candidate = Str::lower((string) (
                    $row['status']
                    ?? $row['triage_level']
                    ?? $row['label']
                    ?? ''
                ));

                $candidate = str_replace('-', '_', $candidate);

                return $candidate === $needle;
            })
            ->sum(fn ($row) => (int) ($row['count'] ?? $row['total'] ?? $row['value'] ?? 0));
    }

    private function triageLevel(string $value): string
    {
        $normalized = Str::lower($value);

        return match ($normalized) {
            'emergency', 'critical', 'urgent' => 'urgent',
            'high' => 'high',
            'moderate' => 'moderate',
            default => 'low',
        };
    }

    private function displayLabel(string $value): string
    {
        return Str::of($value)->replace(['_', '-'], ' ')->title()->toString();
    }

    private function safeCount(string $table): int
    {
        return Schema::hasTable($table)
            ? (int) DB::table($table)->count()
            : 0;
    }

    private function safeCountBetween(
        string $table,
        Carbon $from,
        Carbon $to,
        string $preferredColumn = 'created_at',
        ?int $rhuId = null
    ): int {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $scoped = Schema::hasColumn($table, 'rhu_id');

        $column = $this->dateColumnWithValues($table, $from, $to, [$preferredColumn, 'created_at']);

        if (!$column) {
            return (int) DB::table($table)
                ->when($scoped, fn ($q) => $this->scopeRhu($q, $rhuId, 'rhu_id'))
                ->count();
        }

        return (int) DB::table($table)
            ->whereBetween($column, [$from, $to])
            ->when($scoped, fn ($q) => $this->scopeRhu($q, $rhuId, 'rhu_id'))
            ->count();
    }

    private function barangayCases(Carbon $from, Carbon $to, string $disease = '', ?int $rhuId = null): array
    {
        if (!Schema::hasTable('consultations') || !Schema::hasTable('users')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('consultations', 'consultation_date')
            ? 'consultation_date'
            : 'created_at';

        $hasBarangays = Schema::hasTable('barangays')
            && Schema::hasColumn('users', 'barangay_id');

        $hasUserBarangayText = Schema::hasColumn('users', 'barangay');

        if ($hasBarangays && $hasUserBarangayText) {
            $barangayExpr = "COALESCE(b.name, u.barangay, 'Unspecified')";
        } elseif ($hasBarangays) {
            $barangayExpr = "COALESCE(b.name, 'Unspecified')";
        } elseif ($hasUserBarangayText) {
            $barangayExpr = "COALESCE(u.barangay, 'Unspecified')";
        } else {
            $barangayExpr = "'Unspecified'";
        }

        $complaintParts = [];

        if (Schema::hasColumn('consultations', 'chief_complaint')) {
            $complaintParts[] = "NULLIF(c.chief_complaint, '')";
        }

        if (Schema::hasColumn('consultations', 'diagnosis')) {
            $complaintParts[] = "NULLIF(c.diagnosis, '')";
        }

        if (Schema::hasColumn('consultations', 'assessment')) {
            $complaintParts[] = "NULLIF(c.assessment, '')";
        }

        $complaintExpr = count($complaintParts) > 0
            ? 'COALESCE(' . implode(', ', $complaintParts) . ", 'Unspecified')"
            : "'Unspecified'";

        $query = DB::table('consultations as c')
            ->join('users as u', 'u.user_id', '=', 'c.user_id')
            ->when($hasBarangays, function ($q) {
                $q->leftJoin('barangays as b', 'b.barangay_id', '=', 'u.barangay_id');
            })
            ->selectRaw("{$barangayExpr} as barangay")
            ->selectRaw("COUNT(*) as total_cases")
            ->selectRaw("{$complaintExpr} as top_complaint")
            ->whereBetween("c.{$dateColumn}", [$from, $to])
            ->when(
                $rhuId !== null && $hasBarangays && Schema::hasColumn('barangays', 'rhu_id'),
                fn ($q) => $this->scopeRhu($q, $rhuId, 'b.rhu_id')
            )
            ->when($disease !== '', function ($q) use ($disease) {
                $q->where(function ($inner) use ($disease) {
                    if (Schema::hasColumn('consultations', 'chief_complaint')) {
                        $inner->orWhere('c.chief_complaint', 'ILIKE', "%{$disease}%");
                    }

                    if (Schema::hasColumn('consultations', 'diagnosis')) {
                        $inner->orWhere('c.diagnosis', 'ILIKE', "%{$disease}%");
                    }

                    if (Schema::hasColumn('consultations', 'assessment')) {
                        $inner->orWhere('c.assessment', 'ILIKE', "%{$disease}%");
                    }
                });
            })
            ->groupByRaw("{$barangayExpr}, {$complaintExpr}")
            ->orderByDesc('total_cases')
            ->limit(100)
            ->get();

        $queue = $this->queueByBarangay($rhuId);

        return $query
            ->map(function ($row) use ($queue) {
                return [
                    'barangay' => $row->barangay,
                    'total_cases' => (int) $row->total_cases,
                    'queue_density' => (int) ($queue[$row->barangay] ?? 0),
                    'top_complaint' => $row->top_complaint,
                ];
            })
            ->all();
    }

    private function queueByBarangay(?int $rhuId = null): array
    {
        if (!Schema::hasTable('queue_tickets')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'issued_at')
            ? 'issued_at'
            : 'created_at';

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasTable('barangays') &&
            Schema::hasColumn('queue_tickets', 'resident_profile_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            return DB::table('queue_tickets as q')
                ->join('resident_profiles as rp', 'rp.id', '=', 'q.resident_profile_id')
                ->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
                ->selectRaw("COALESCE(b.name, 'Unspecified') as barangay")
                ->selectRaw("COUNT(*) as total")
                ->whereIn('q.status', ['waiting', 'called', 'in_service'])
                ->whereDate("q.{$dateColumn}", today())
                ->when(Schema::hasColumn('queue_tickets', 'rhu_id'), fn ($q) => $this->scopeRhu($q, $rhuId, 'q.rhu_id'))
                ->when(Schema::hasColumn('queue_tickets', 'deleted_at'), function ($q) {
                    $q->whereNull('q.deleted_at');
                })
                ->groupByRaw("COALESCE(b.name, 'Unspecified')")
                ->pluck('total', 'barangay')
                ->map(fn ($value) => (int) $value)
                ->all();
        }

        return [];
    }

    private function complaintDistribution(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        if (!Schema::hasTable('consultations')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('consultations', 'consultation_date')
            ? 'consultation_date'
            : 'created_at';

        $complaintParts = [];

        if (Schema::hasColumn('consultations', 'chief_complaint')) {
            $complaintParts[] = "NULLIF(chief_complaint, '')";
        }

        if (Schema::hasColumn('consultations', 'diagnosis')) {
            $complaintParts[] = "NULLIF(diagnosis, '')";
        }

        if (Schema::hasColumn('consultations', 'assessment')) {
            $complaintParts[] = "NULLIF(assessment, '')";
        }

        $complaintExpr = count($complaintParts) > 0
            ? 'COALESCE(' . implode(', ', $complaintParts) . ", 'Unspecified')"
            : "'Unspecified'";

        return DB::table('consultations')
            ->selectRaw("{$complaintExpr} as complaint")
            ->selectRaw("COUNT(*) as total")
            ->whereBetween($dateColumn, [$from, $to])
            ->when(
                $rhuId !== null
                    && Schema::hasTable('barangays')
                    && Schema::hasColumn('barangays', 'rhu_id')
                    && Schema::hasColumn('users', 'barangay_id'),
                fn ($q) => $q->whereIn('user_id', function ($sub) use ($rhuId) {
                    $sub->from('users')
                        ->join('barangays as b', 'b.barangay_id', '=', 'users.barangay_id')
                        ->select('users.user_id');
                    $this->scopeRhu($sub, $rhuId, 'b.rhu_id');
                })
            )
            ->groupByRaw($complaintExpr)
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'complaint' => $row->complaint,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function riskSummary(Carbon $from, Carbon $to, ?int $rhuId = null): array
    {
        return collect($this->barangayCases($from, $to, '', $rhuId))
            ->map(function ($row) {
                $score = min(
                    100,
                    ((int) ($row['total_cases'] ?? 0) * 7) +
                    ((int) ($row['queue_density'] ?? 0) * 3)
                );

                return $this->riskLevel($score);
            })
            ->countBy()
            ->all();
    }

    private function completionRate(Carbon $from, Carbon $to): float
    {
        if (!Schema::hasTable('telemedicine_sessions')) {
            return 0;
        }

        $total = DB::table('telemedicine_sessions')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        if ($total === 0) {
            return 0;
        }

        $completed = DB::table('telemedicine_sessions')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', ['ended', 'completed'])
            ->count();

        return round(($completed / $total) * 100, 1);
    }

    private function riskLevel(int $score): string
    {
        return match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'moderate',
            default => 'low',
        };
    }

    private function empty(string $key): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [],
            'key' => $key,
        ]);
    }
}
