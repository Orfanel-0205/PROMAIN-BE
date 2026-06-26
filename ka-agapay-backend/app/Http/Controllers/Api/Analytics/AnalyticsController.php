<?php
// app/Http/Controllers/Api/Analytics/AnalyticsController.php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\HeatmapAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Reports\DiagnosisItrReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AnalyticsController extends Controller

{
    /**
     * GET /api/v1/analytics/realtime
     *
     * One-request realtime analytics payload for the Analytics page.
     * This replaces multiple frontend polling requests.
     */
    public function realtime(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date', 'after_or_equal:from'],
            'disease' => ['nullable', 'string', 'max:100'],
        ]);

        [$from, $to] = $this->dateRange($request);

        $disease = trim((string) ($validated['disease'] ?? ''));

        $barangayCases = $this->barangayCases($from, $to, $disease);

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

        $clusters = collect($this->complaintDistribution($from, $to))
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
                    'total_consultations' => $this->safeCountBetween('consultations', $from, $to, 'consultation_date'),
                    'total_telemedicine_requests' => $this->safeCountBetween('telemedicine_requests', $from, $to),
                    'total_queue_tickets' => $this->safeCountBetween('queue_tickets', $from, $to, 'issued_at'),
                    'total_chat_messages' => $this->safeCountBetween('chat_messages', $from, $to),
                    ...$this->operationsOverview($from, $to),
                    'barangay_cases' => $barangayCases,
                    'complaint_distribution' => $clusters,
                    'risk_summary' => $this->riskSummary($from, $to),
                ],
                'heatmap' => $heatmap,
                'risk' => $risk,
                'clusters' => $clusters,
                'chatbot' => $chatbot,
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/overview
     */
    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'data' => [
                'total_patients' => $this->safeCount('users'),
                'total_consultations' => $this->safeCountBetween('consultations', $from, $to, 'consultation_date'),
                'total_telemedicine_requests' => $this->safeCountBetween('telemedicine_requests', $from, $to),
                'total_queue_tickets' => $this->safeCountBetween('queue_tickets', $from, $to, 'issued_at'),
                'total_chat_messages' => $this->safeCountBetween('chat_messages', $from, $to),
                ...$this->operationsOverview($from, $to),
                'barangay_cases' => $this->barangayCases($from, $to),
                'complaint_distribution' => $this->complaintDistribution($from, $to),
                'risk_summary' => $this->riskSummary($from, $to),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/heatmap
     *
     * GIS-ready barangay health heatmap.
     *
     * Query params:
     * - disease: optional disease/diagnosis filter
     * - range: week | month
     */
   public function heatmap(Request $request, HeatmapAnalyticsService $service): JsonResponse
{
    $validated = $request->validate([
        'disease' => ['nullable', 'string', 'max:100'],
        'range' => ['nullable', 'in:week,month'],
        'active_only' => ['nullable', 'boolean'],
    ]);

    $disease = trim((string) ($validated['disease'] ?? ''));
    $range = $validated['range'] ?? 'week';
    $activeOnly = $request->boolean('active_only', true);

    $points = $service->generateHeatmapData(
        $disease !== '' ? $disease : null,
        $range,
        $activeOnly
    );

    return response()->json([
        'status' => 'success',
        'generated_at' => now()->toIso8601String(),
        'filters' => [
            'disease' => $disease !== '' ? $disease : null,
            'range' => $range,
            'active_only' => $activeOnly,
        ],
        'data' => $points,
    ]);
}
    /**
     * GET /api/v1/analytics/queue-performance
     */
    public function queuePerformance(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        if (!Schema::hasTable('queue_tickets')) {
            return $this->empty('queue_performance');
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'issued_at')
            ? 'issued_at'
            : 'created_at';

        $byStatus = DB::table('queue_tickets')
            ->selectRaw('status, COUNT(*) as total, AVG(wait_time_minutes) as avg_wait, AVG(service_time_minutes) as avg_service')
            ->whereBetween($dateColumn, [$from, $to])
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $byService = DB::table('queue_tickets')
            ->selectRaw("COALESCE(service_type, 'unspecified') as service_type, COUNT(*) as total, AVG(wait_time_minutes) as avg_wait")
            ->whereBetween($dateColumn, [$from, $to])
            ->groupByRaw("COALESCE(service_type, 'unspecified')")
            ->orderByDesc('total')
            ->get();

        $averageWait = DB::table('queue_tickets')
            ->whereBetween($dateColumn, [$from, $to])
            ->avg('wait_time_minutes');

        return response()->json([
            'status' => 'success',
            'data' => [
                'by_status' => $byStatus,
                'by_service' => $byService,
                'average_wait_minutes' => round((float) $averageWait, 1),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/telemedicine-summary
     */
    public function telemedicineSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        if (!Schema::hasTable('telemedicine_requests')) {
            return $this->empty('telemedicine_summary');
        }

        $requests = DB::table('telemedicine_requests')
            ->selectRaw('status, urgency_level, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status', 'urgency_level')
            ->orderByDesc('total')
            ->get();

        $sessions = Schema::hasTable('telemedicine_sessions')
            ? DB::table('telemedicine_sessions')
                ->selectRaw('status, COUNT(*) as total')
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('status')
                ->orderByDesc('total')
                ->get()
            : collect();

        return response()->json([
            'status' => 'success',
            'data' => [
                'requests' => $requests,
                'sessions' => $sessions,
                'completion_rate' => $this->completionRate($from, $to),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/barangay-health-profile
     */
    public function barangayHealthProfile(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->barangayCases($from, $to),
        ]);
    }

    /**
     * GET /api/v1/analytics/ai-accuracy
     */
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

    /**
     * GET /api/v1/analytics/registration-stats
     */
    public function registrationStats(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        if (!Schema::hasTable('event_registrations')) {
            return $this->empty('registration_stats');
        }

        $rows = DB::table('event_registrations')
            ->selectRaw('status, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    /**
     * GET /api/v1/analytics/chatbot-usage
     */
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

    /**
     * GET /api/v1/analytics/queue-heatmap
     */
   public function queueHeatmap(Request $request, HeatmapAnalyticsService $service): JsonResponse
{
    $validated = $request->validate([
        'disease' => ['nullable', 'string', 'max:100'],
        'range' => ['nullable', 'in:week,month'],
        'active_only' => ['nullable', 'boolean'],
    ]);

    $disease = trim((string) ($validated['disease'] ?? ''));
    $range = $validated['range'] ?? 'week';
    $activeOnly = $request->boolean('active_only', true);

    $points = $service->generateHeatmapData(
        $disease !== '' ? $disease : null,
        $range,
        $activeOnly
    );

    return response()->json([
        'status' => 'success',
        'generated_at' => now()->toIso8601String(),
        'filters' => [
            'disease' => $disease !== '' ? $disease : null,
            'range' => $range,
            'active_only' => $activeOnly,
        ],
        'data' => $points,
    ]);
}
    /**
     * GET /api/v1/analytics/barangay-risk
     */
   public function barangayRisk(Request $request, HeatmapAnalyticsService $service): JsonResponse
{
    $validated = $request->validate([
        'disease' => ['nullable', 'string', 'max:100'],
        'range' => ['nullable', 'in:week,month'],
        'active_only' => ['nullable', 'boolean'],
    ]);

    $disease = trim((string) ($validated['disease'] ?? ''));
    $range = $validated['range'] ?? 'week';
    $activeOnly = $request->boolean('active_only', true);

    $items = collect(
        $service->generateHeatmapData(
            $disease !== '' ? $disease : null,
            $range,
            $activeOnly
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
        ],
        'summary' => $items->countBy('risk_level'),
        'data' => $items,
    ]);
}

    /**
     * GET /api/v1/analytics/queue-density
     */
    public function queueDensity(Request $request): JsonResponse
    {
        if (!Schema::hasTable('queue_tickets')) {
            return $this->empty('queue_density');
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'issued_at')
            ? 'issued_at'
            : 'created_at';

        $rows = DB::table('queue_tickets')
            ->selectRaw("COALESCE(service_type, 'unspecified') as service_type, status, COUNT(*) as total")
            ->whereDate($dateColumn, today())
            ->groupByRaw("COALESCE(service_type, 'unspecified'), status")
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
        ]);
    }

    /**
     * GET /api/v1/analytics/disease-clusters
     */
    public function diseaseClusters(Request $request): JsonResponse
    {
        [$from, $to] = $this->dateRange($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->complaintDistribution($from, $to),
        ]);
    }

    /**
     * GET /api/v1/analytics/outbreak-alerts
     */
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

    /**
     * POST /api/v1/analytics/outbreak-alerts/{id}/resolve
     */
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

    /**
     * GET /api/v1/analytics/priority-dashboard
     */
    public function priorityDashboard(Request $request): JsonResponse
    {
        if (!Schema::hasTable('queue_tickets')) {
            return $this->empty('priority_dashboard');
        }

        $rows = DB::table('queue_tickets')
            ->selectRaw("COALESCE(priority_category, 'regular') as priority_category, COUNT(*) as total, AVG(priority_score) as avg_score")
            ->whereIn('status', ['waiting', 'called', 'in_service'])
            ->groupByRaw("COALESCE(priority_category, 'regular')")
            ->orderByDesc('avg_score')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
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
        /**
     * GET /api/v1/analytics/diagnosis-itr-summary
     *
     * Diagnosis + ITR summary for the Analytics dashboard.
     */
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

    /**
     * GET /api/v1/analytics/heatmap/diagnosis-itr-signals
     *
     * Completed consultation heatmap signals with Diagnosis + ITR fields.
     */
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

    private function operationsOverview(Carbon $from, Carbon $to): array
    {
        $attendance = $this->attendanceOverview($from, $to);
        $queue = $this->queueOperations($from, $to);
        $appointments = $this->statusBreakdown('appointments', $from, $to, 'appointment_date');
        $telemedicine = $this->statusBreakdown('telemedicine_requests', $from, $to);
        $programs = $this->programParticipation($from, $to);
        $aiTriage = $this->aiTriageDistribution($from, $to);

        return [
            ...$attendance,
            ...$queue,
            'appointments_by_status' => $appointments,
            'appointment_status_distribution' => $appointments,
            'telemedicine_by_status' => $telemedicine,
            'telemedicine_status_distribution' => $telemedicine,
            'program_participation_by_barangay' => $programs,
            'ai_triage_distribution' => $aiTriage,
            'priority_patient_breakdown' => $queue['priority_breakdown'] ?? [],
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

    private function attendanceOverview(Carbon $from, Carbon $to): array
    {
        $source = 'completed_consultations';
        $timestamps = $this->consultationAttendanceTimestamps($from, $to);

        if ($timestamps->isEmpty()) {
            $source = 'queue_tickets';
            $timestamps = $this->tableTimestamps('queue_tickets', $from, $to, 'issued_at');
        }

        if ($timestamps->isEmpty()) {
            $source = 'completed_appointments';
            $timestamps = $this->tableTimestamps(
                'appointments',
                $from,
                $to,
                'appointment_date',
                ['completed', 'done', 'served']
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

    private function consultationAttendanceTimestamps(Carbon $from, Carbon $to)
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
            $query->leftJoin('appointments as a', 'a.id', '=', 'c.appointment_id')
                ->where(function ($q) {
                    $q->where('a.rhu_id', 1)->orWhereNull('a.rhu_id');
                });
        }

        return $query->pluck("c.{$dateColumn}");
    }

    private function tableTimestamps(
        string $table,
        Carbon $from,
        Carbon $to,
        string $preferredColumn = 'created_at',
        array $statuses = []
    ) {
        if (!Schema::hasTable($table)) {
            return collect();
        }

        $dateColumn = Schema::hasColumn($table, $preferredColumn)
            ? $preferredColumn
            : (Schema::hasColumn($table, 'created_at') ? 'created_at' : null);

        if (!$dateColumn) {
            return collect();
        }

        return DB::table($table)
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn($table, 'rhu_id'), fn ($q) => $q->where('rhu_id', 1))
            ->when($statuses && Schema::hasColumn($table, 'status'), fn ($q) => $q->whereIn('status', $statuses))
            ->pluck($dateColumn);
    }

    private function queueOperations(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('queue_tickets')) {
            return [
                'queue_by_hour' => [],
                'queue_by_status' => [],
                'priority_breakdown' => [],
                'average_wait_minutes' => 0,
                'peak_queue_hour' => null,
            ];
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'issued_at')
            ? 'issued_at'
            : 'created_at';

        $base = DB::table('queue_tickets')
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn('queue_tickets', 'rhu_id'), fn ($q) => $q->where('rhu_id', 1));

        $timestamps = (clone $base)->pluck($dateColumn);
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
            ->map(fn ($total, $hour) => [
                'hour' => (int) $hour,
                'label' => Carbon::createFromTime((int) $hour)->format('g A'),
                'total' => (int) $total,
            ])
            ->filter(fn ($row) => $row['total'] > 0)
            ->values()
            ->all();

        $queueByStatus = (clone $base)
            ->selectRaw("COALESCE(status, 'unknown') as status, COUNT(*) as total")
            ->groupByRaw("COALESCE(status, 'unknown')")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) $row->status,
                'label' => $this->displayLabel((string) $row->status),
                'total' => (int) $row->total,
            ])
            ->all();

        $priorityBreakdown = [
            ['label' => 'Senior Citizen', 'total' => (int) (clone $base)->where('is_senior', true)->count()],
            ['label' => 'PWD', 'total' => (int) (clone $base)->where('is_pwd', true)->count()],
            ['label' => 'Pregnant', 'total' => (int) (clone $base)->where('is_pregnant', true)->count()],
            ['label' => 'Child', 'total' => (int) (clone $base)->where('is_pediatric', true)->count()],
            ['label' => 'Urgent / Emergency', 'total' => (int) (clone $base)->where(function ($q) {
                $q->where('is_emergency', true)->orWhere('priority_score', '>=', 80);
            })->count()],
            ['label' => 'Regular', 'total' => (int) (clone $base)->where(function ($q) {
                $q->whereNull('priority_category')->orWhere('priority_category', 'regular');
            })->where('priority_score', '<', 35)->count()],
        ];

        $peak = collect($queueByHour)->sortByDesc('total')->first();

        return [
            'queue_by_hour' => $queueByHour,
            'queue_by_status' => $queueByStatus,
            'priority_breakdown' => array_values(array_filter($priorityBreakdown, fn ($row) => $row['total'] > 0)),
            'average_wait_minutes' => round((float) (clone $base)->avg('wait_time_minutes'), 1),
            'peak_queue_hour' => $peak,
            'waiting_queue' => $this->statusTotal($queueByStatus, 'waiting'),
            'currently_serving' => $this->statusTotal($queueByStatus, 'in_service') + $this->statusTotal($queueByStatus, 'called'),
            'served_queue' => $this->statusTotal($queueByStatus, 'completed'),
            'skipped_queue' => $this->statusTotal($queueByStatus, 'skipped'),
            'cancelled_queue' => $this->statusTotal($queueByStatus, 'cancelled'),
            'priority_queue' => (int) (clone $base)->where('priority_score', '>=', 35)->count(),
            'regular_queue' => (int) (clone $base)->where('priority_score', '<', 35)->count(),
        ];
    }

    private function statusBreakdown(string $table, Carbon $from, Carbon $to, string $preferredColumn = 'created_at'): array
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'status')) {
            return [];
        }

        $dateColumn = Schema::hasColumn($table, $preferredColumn)
            ? $preferredColumn
            : (Schema::hasColumn($table, 'created_at') ? 'created_at' : null);

        if (!$dateColumn) {
            return [];
        }

        return DB::table($table)
            ->selectRaw("COALESCE(status, 'unknown') as status, COUNT(*) as total")
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn($table, 'rhu_id'), fn ($q) => $q->where('rhu_id', 1))
            ->groupByRaw("COALESCE(status, 'unknown')")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) $row->status,
                'label' => $this->displayLabel((string) $row->status),
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function programParticipation(Carbon $from, Carbon $to): array
    {
        if (!Schema::hasTable('event_registrations')) {
            return [];
        }

        $query = DB::table('event_registrations as er')
            ->whereBetween('er.created_at', [$from, $to]);

        $barangayExpr = "'Unspecified'";

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('event_registrations', 'resident_profile_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id') &&
            Schema::hasTable('barangays')
        ) {
            $query->leftJoin('resident_profiles as rp', 'rp.id', '=', 'er.resident_profile_id')
                ->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id');
            $barangayExpr = "COALESCE(b.name, 'Unspecified')";
        }

        return $query
            ->selectRaw("{$barangayExpr} as barangay, COUNT(*) as total")
            ->groupByRaw($barangayExpr)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'barangay' => (string) $row->barangay,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function aiTriageDistribution(Carbon $from, Carbon $to): array
    {
        if (Schema::hasTable('ai_triage_scores')) {
            $stored = DB::table('ai_triage_scores')
                ->selectRaw("COALESCE(recommended_urgency, 'low') as triage_level, COUNT(*) as total, AVG(ai_score) as average_score")
                ->whereBetween('created_at', [$from, $to])
                ->groupByRaw("COALESCE(recommended_urgency, 'low')")
                ->orderByDesc('total')
                ->get()
                ->map(function ($row) {
                    $level = $this->triageLevel((string) $row->triage_level);

                    return [
                        'triage_level' => $level,
                        'label' => $this->displayLabel($level),
                        'total' => (int) $row->total,
                        'average_score' => round((float) $row->average_score, 1),
                    ];
                })
                ->all();

            if (array_sum(array_column($stored, 'total')) > 0) {
                return $stored;
            }
        }

        if (!Schema::hasTable('queue_tickets')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'issued_at')
            ? 'issued_at'
            : 'created_at';

        $rows = DB::table('queue_tickets')
            ->whereBetween($dateColumn, [$from, $to])
            ->when(Schema::hasColumn('queue_tickets', 'rhu_id'), fn ($q) => $q->where('rhu_id', 1))
            ->get([
                'priority_score',
                'is_emergency',
                'is_senior',
                'is_pwd',
                'is_pregnant',
                'is_pediatric',
                'notes',
            ]);

        $counts = [
            'urgent' => ['total' => 0, 'score' => 0],
            'high' => ['total' => 0, 'score' => 0],
            'moderate' => ['total' => 0, 'score' => 0],
            'low' => ['total' => 0, 'score' => 0],
        ];

        foreach ($rows as $row) {
            $score = (int) ($row->priority_score ?? 0);
            $notes = Str::lower((string) ($row->notes ?? ''));

            if ((bool) $row->is_emergency || $score >= 80 || $this->containsUrgentComplaint($notes)) {
                $level = 'urgent';
                $score = max($score, 85);
            } elseif ((bool) $row->is_pregnant || ((bool) $row->is_senior && $notes !== '') || ((bool) $row->is_pwd && $notes !== '') || $score >= 60) {
                $level = 'high';
                $score = max($score, 65);
            } elseif ((bool) $row->is_pediatric || $score >= 35 || $this->containsModerateComplaint($notes)) {
                $level = 'moderate';
                $score = max($score, 35);
            } else {
                $level = 'low';
                $score = max($score, 10);
            }

            $counts[$level]['total']++;
            $counts[$level]['score'] += $score;
        }

        return collect($counts)
            ->map(fn ($row, $level) => [
                'triage_level' => $level,
                'label' => $this->displayLabel($level),
                'total' => (int) $row['total'],
                'average_score' => $row['total'] > 0 ? round($row['score'] / $row['total'], 1) : 0,
            ])
            ->filter(fn ($row) => $row['total'] > 0)
            ->values()
            ->all();
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
        $needle = Str::lower($status);

        return (int) collect($rows)
            ->filter(fn ($row) => Str::lower((string) ($row['status'] ?? $row['triage_level'] ?? '')) === $needle)
            ->sum('total');
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
        string $preferredColumn = 'created_at'
    ): int {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        if (Schema::hasColumn($table, $preferredColumn)) {
            $column = $preferredColumn;
        } elseif (Schema::hasColumn($table, 'created_at')) {
            $column = 'created_at';
        } else {
            return (int) DB::table($table)->count();
        }

        return (int) DB::table($table)
            ->whereBetween($column, [$from, $to])
            ->count();
    }

    /**
     * PostgreSQL-safe barangay case aggregation.
     */
    private function barangayCases(Carbon $from, Carbon $to, string $disease = ''): array
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

        $queue = $this->queueByBarangay();

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

    /**
     * Real-time queue density grouped by barangay.
     */
    private function queueByBarangay(): array
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

    /**
     * PostgreSQL-safe complaint distribution.
     */
    private function complaintDistribution(Carbon $from, Carbon $to): array
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

    private function riskSummary(Carbon $from, Carbon $to): array
    {
        return collect($this->barangayCases($from, $to))
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
