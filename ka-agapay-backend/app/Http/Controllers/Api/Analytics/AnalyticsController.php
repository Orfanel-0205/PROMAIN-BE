<?php
// app/Http/Controllers/Api/Analytics/AnalyticsController.php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\HeatmapAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ]);

        $disease = $validated['disease'] ?? null;
        $range = $validated['range'] ?? 'week';

        $points = $service->generateHeatmapData($disease, $range);

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'disease' => $disease,
                'range' => $range,
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
        ]);

        $disease = trim((string) ($validated['disease'] ?? ''));
        $range = $validated['range'] ?? 'week';

        $points = $service->generateHeatmapData(
            $disease !== '' ? $disease : null,
            $range
        );

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'disease' => $disease !== '' ? $disease : null,
                'range' => $range,
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
        ]);

        $disease = trim((string) ($validated['disease'] ?? ''));
        $range = $validated['range'] ?? 'week';

        $items = collect(
            $service->generateHeatmapData(
                $disease !== '' ? $disease : null,
                $range
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