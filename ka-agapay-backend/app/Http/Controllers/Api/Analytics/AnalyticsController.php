<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\HeatmapAlertService;
use App\Services\Analytics\HeatmapAnalyticsService;
use App\Services\Queue\QueuePrioritizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Analytics Controller — Heatmap & Queue Intelligence Dashboard
 * =============================================================
 *
 * Lightweight controller: zero business logic lives here.
 * All computation is delegated to the three analytics services.
 *
 * Endpoints:
 *   GET /api/v1/analytics/queue-heatmap
 *   GET /api/v1/analytics/barangay-risk
 *   GET /api/v1/analytics/queue-density
 *   GET /api/v1/analytics/outbreak-alerts
 *   GET /api/v1/analytics/priority-dashboard
 *   POST /api/v1/analytics/outbreak-alerts/{id}/resolve
 *   GET  /api/v1/analytics/disease-clusters
 *
 * Caching Strategy:
 *   Heatmap and risk data are cached for 5 minutes (TTL configurable)
 *   to prevent redundant database aggregations during high-traffic
 *   dashboard polling. Cache is tagged for granular invalidation.
 *
 * Security:
 *   All routes require 'auth:sanctum' + 'role:admin,staff,super_admin'
 *   middleware applied in routes/api.php.
 */
class AnalyticsController extends Controller
{
    private const HEATMAP_CACHE_TTL   = 300;   // 5 minutes
    private const DASHBOARD_CACHE_TTL = 120;   // 2 minutes

    public function __construct(
        private readonly HeatmapAnalyticsService  $heatmapService,
        private readonly HeatmapAlertService      $alertService,
        private readonly QueuePrioritizationService $queuePriorityService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // HEATMAP ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/analytics/queue-heatmap
     *
     * Returns GIS-ready heatmap data for all barangays, combining
     * consultation case counts with real-time queue density.
     *
     * Query Parameters:
     *   disease  (string, optional)  — filter by disease keyword
     *   range    (string, optional)  — 'week' (default) or 'month'
     *
     * Response:
     * {
     *   "status": "success",
     *   "generated_at": "2026-05-27T10:00:00Z",
     *   "filters": { "disease": null, "range": "week" },
     *   "data": [
     *     {
     *       "barangay_id": 57,
     *       "barangay": "Poblacion",
     *       "latitude": 15.9196,
     *       "longitude": 120.4123,
     *       "total_cases": 54,
     *       "queue_density": 32,
     *       "incidence_rate": 15.43,
     *       "heatmap_intensity": 8.7,
     *       "risk_level": "critical",
     *       "top_case_type": "Respiratory"
     *     }
     *   ]
     * }
     */
    public function queueHeatmap(Request $request): JsonResponse
    {
        $request->validate([
            'disease' => ['nullable', 'string', 'max:100'],
            'range'   => ['nullable', 'in:week,month'],
        ]);

        $disease = $request->input('disease');
        $range   = $request->input('range', 'week');

        $cacheKey = "heatmap.queue.{$range}." . ($disease ?? 'all');

        $data = Cache::remember($cacheKey, self::HEATMAP_CACHE_TTL, function () use ($disease, $range) {
            return $this->heatmapService->generateHeatmapData($disease, $range);
        });

        return response()->json([
            'status'       => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters'      => ['disease' => $disease, 'range' => $range],
            'count'        => count($data),
            'data'         => $data,
        ]);
    }

    /**
     * GET /api/v1/analytics/barangay-risk
     *
     * Returns risk classification summary across all barangays.
     * Used for the choropleth risk overlay on the GIS map.
     *
     * Response:
     * {
     *   "status": "success",
     *   "summary": {
     *     "total_barangays": 73,
     *     "risk_distribution": {
     *       "critical": 2, "high": 8, "moderate": 15, "low": 48
     *     }
     *   },
     *   "barangays": [ ... ]
     * }
     */
    public function barangayRisk(): JsonResponse
    {
        $cacheKey = 'heatmap.barangay_risk.' . today()->toDateString();

        $result = Cache::remember($cacheKey, self::HEATMAP_CACHE_TTL, function () {
            return $this->heatmapService->getBarangayRiskSummary();
        });

        return response()->json([
            'status'    => 'success',
            'summary'   => $result['summary'],
            'barangays' => $result['barangays'],
        ]);
    }

    /**
     * GET /api/v1/analytics/queue-density
     *
     * Returns hourly queue congestion trend data for an RHU.
     * Used for staffing recommendations and peak-hour identification.
     *
     * Query Parameters:
     *   rhu_id  (int, required)
     *   date    (date Y-m-d, optional, defaults to today)
     *
     * Response:
     * {
     *   "status": "success",
     *   "rhu_id": 1,
     *   "date": "2026-05-27",
     *   "congestion": {
     *     "total_waiting": 24,
     *     "avg_wait_minutes": 18.5,
     *     "congestion_level": "moderate",
     *     "by_priority": { "critical": 1, "high": 4, "moderate": 9, "low": 10 }
     *   },
     *   "hourly_trend": [ ... ]
     * }
     */
    public function queueDensity(Request $request): JsonResponse
    {
        $request->validate([
            'rhu_id' => ['required', 'integer', 'min:1'],
            'date'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $rhuId = (int) $request->input('rhu_id');
        $date  = $request->input('date', today()->toDateString());

        $congestion  = $this->queuePriorityService->getQueueCongestion($rhuId);
        $hourlyTrend = $this->heatmapService->getQueueDensityTrends($rhuId, $date);

        return response()->json([
            'status'       => 'success',
            'rhu_id'       => $rhuId,
            'date'         => $date,
            'congestion'   => $congestion,
            'hourly_trend' => $hourlyTrend->values(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ALERT ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/analytics/outbreak-alerts
     *
     * Returns active heatmap alerts, optionally filtered by severity
     * or alert type. Includes alert summary counts for the badge display.
     *
     * Query Parameters:
     *   severity    (string, optional)  — 'low' | 'moderate' | 'high' | 'critical'
     *   alert_type  (string, optional)  — 'outbreak_spike' | 'congestion_alert'
     *
     * Response:
     * {
     *   "status": "success",
     *   "summary": { "total": 3, "critical": 1, "high": 2, "moderate": 0, "low": 0 },
     *   "alerts": [ ... ]
     * }
     */
    public function outbreakAlerts(Request $request): JsonResponse
    {
        $request->validate([
            'severity'   => ['nullable', 'in:low,moderate,high,critical'],
            'alert_type' => ['nullable', 'in:outbreak_spike,congestion_alert,high_risk_zone'],
        ]);

        $alerts  = $this->alertService->getActiveAlerts(
            $request->input('severity'),
            $request->input('alert_type')
        );

        $summary = $this->alertService->getAlertSummary();

        return response()->json([
            'status'  => 'success',
            'summary' => $summary,
            'count'   => $alerts->count(),
            'alerts'  => $alerts->map(fn($a) => [
                'id'               => $a->id,
                'barangay_id'      => $a->barangay_id,
                'barangay_name'    => $a->barangay?->name,
                'latitude'         => $a->barangay?->latitude,
                'longitude'        => $a->barangay?->longitude,
                'disease_type'     => $a->disease_type,
                'alert_type'       => $a->alert_type,
                'severity'         => $a->severity,
                'trigger_message'  => $a->trigger_message,
                'case_count'       => $a->case_count,
                'baseline_average' => $a->baseline_average,
                'deviation_factor' => $a->deviation_factor,
                'created_at'       => $a->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * POST /api/v1/analytics/outbreak-alerts/{id}/resolve
     *
     * Mark a heatmap alert as resolved. Records the resolver and notes.
     *
     * Body:
     *   notes  (string, optional)
     */
    public function resolveAlert(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $resolved = $this->alertService->resolveAlert(
            alertId:          $id,
            resolvedByUserId: $request->user()->user_id,
            notes:            $request->input('notes', '')
        );

        if (!$resolved) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Alert not found or already resolved.',
            ], 404);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Alert resolved successfully.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // DASHBOARD ENDPOINT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/analytics/priority-dashboard
     *
     * Aggregated RHU operations dashboard combining:
     *   - Alert summary
     *   - Current queue congestion
     *   - Top 5 high-risk barangays
     *   - Disease cluster summary
     *
     * Cached for 2 minutes; used for the real-time admin dashboard.
     *
     * Query Parameters:
     *   rhu_id  (int, required)
     *
     * Response:
     * {
     *   "status": "success",
     *   "generated_at": "...",
     *   "rhu_id": 1,
     *   "alert_summary": { ... },
     *   "queue_congestion": { ... },
     *   "top_risk_barangays": [ ... ],
     *   "disease_clusters": [ ... ]
     * }
     */
    public function priorityDashboard(Request $request): JsonResponse
    {
        $request->validate([
            'rhu_id' => ['required', 'integer', 'min:1'],
        ]);

        $rhuId    = (int) $request->input('rhu_id');
        $cacheKey = "analytics.dashboard.rhu_{$rhuId}." . today()->toDateString();

        $dashboard = Cache::remember($cacheKey, self::DASHBOARD_CACHE_TTL, function () use ($rhuId) {
            // High-risk barangay snapshot — top 5
            $riskData         = $this->heatmapService->getBarangayRiskSummary();
            $topRiskBarangays = array_slice($riskData['barangays'], 0, 5);

            // Disease clusters for the past 14 days
            $recentClusters = \App\Models\DiseaseCluster::recent(14)
                ->orderByDesc('detected_at')
                ->limit(10)
                ->get(['disease_type', 'case_count', 'barangay_count',
                       'center_latitude', 'center_longitude', 'radius_km',
                       'density_index', 'affected_barangays', 'detected_at']);

            return [
                'alert_summary'      => $this->alertService->getAlertSummary(),
                'queue_congestion'   => $this->queuePriorityService->getQueueCongestion($rhuId),
                'top_risk_barangays' => $topRiskBarangays,
                'disease_clusters'   => $recentClusters->map(fn($c) => [
                    'disease_type'       => $c->disease_type,
                    'case_count'         => $c->case_count,
                    'barangay_count'     => $c->barangay_count,
                    'center_latitude'    => $c->center_latitude,
                    'center_longitude'   => $c->center_longitude,
                    'radius_km'          => $c->radius_km,
                    'density_index'      => $c->density_index,
                    'affected_barangays' => $c->affected_barangays,
                    'detected_at'        => $c->detected_at?->toIso8601String(),
                ]),
            ];
        });

        return response()->json(array_merge(
            ['status' => 'success', 'generated_at' => now()->toIso8601String(), 'rhu_id' => $rhuId],
            $dashboard
        ));
    }

    /**
     * GET /api/v1/analytics/disease-clusters
     *
     * Run cluster detection for a specific disease and return results.
     * This is a heavier endpoint; call sparingly (or cache upstream).
     *
     * Query Parameters:
     *   disease  (string, required)
     *   days     (int, optional, default 14)
     */
    public function diseaseClusters(Request $request): JsonResponse
    {
        $request->validate([
            'disease' => ['required', 'string', 'max:100'],
            'days'    => ['nullable', 'integer', 'min:3', 'max:90'],
        ]);

        $clusters = $this->heatmapService->detectDiseaseClusters(
            $request->input('disease'),
            (int) $request->input('days', 14)
        );

        return response()->json([
            'status'   => 'success',
            'disease'  => $request->input('disease'),
            'count'    => count($clusters),
            'clusters' => $clusters,
        ]);
    }
}