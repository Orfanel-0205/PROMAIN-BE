<?php
// app/Http/Controllers/Api/Analytics/AnalyticsController.php

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * GET /api/v1/analytics/queue-performance
     */
    public function queuePerformance(Request $request): JsonResponse
    {
        $this->requireAnalyticsRole($request);

        $request->validate([
            'rhu_id' => ['required', 'integer'],
            'from'   => ['required', 'date'],
            'to'     => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = DB::select("
            SELECT *
            FROM vw_queue_performance
            WHERE rhu_id = ?
              AND queue_date BETWEEN ? AND ?
            ORDER BY queue_date
        ", [$request->rhu_id, $request->from, $request->to]);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/analytics/telemedicine-summary
     */
    public function telemedicineSummary(Request $request): JsonResponse
    {
        $this->requireAnalyticsRole($request);

        $request->validate([
            'rhu_id' => ['required', 'integer'],
            'from'   => ['required', 'date'],
            'to'     => ['required', 'date'],
        ]);

        $data = DB::select("
            SELECT *
            FROM vw_telemedicine_summary
            WHERE rhu_id = ?
              AND request_date BETWEEN ? AND ?
            ORDER BY request_date
        ", [$request->rhu_id, $request->from, $request->to]);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/analytics/prescription-summary
     */
    public function prescriptionSummary(Request $request): JsonResponse
    {
        $this->requireAnalyticsRole($request);

        $request->validate([
            'rhu_id' => ['required', 'integer'],
            'from'   => ['required', 'date'],
            'to'     => ['required', 'date'],
        ]);

        $data = DB::select("
            SELECT *
            FROM vw_prescription_summary
            WHERE rhu_id = ?
              AND rx_date BETWEEN ? AND ?
            ORDER BY rx_date
        ", [$request->rhu_id, $request->from, $request->to]);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/analytics/barangay-health-profile
     */
    public function barangayHealthProfile(Request $request): JsonResponse
    {
        $this->requireAnalyticsRole($request);

        $data = DB::select("SELECT * FROM vw_barangay_health_profile");

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/analytics/ai-accuracy
     * AI triage acceptance rate — your thesis metrics slide.
     */
    public function aiAccuracy(Request $request): JsonResponse
    {
        $this->requireAnalyticsRole($request);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $data = DB::select("
            SELECT *
            FROM vw_ai_triage_accuracy
            WHERE score_date BETWEEN ? AND ?
            ORDER BY score_date DESC
        ", [
            $request->from ?? now()->subDays(30)->toDateString(),
            $request->to   ?? now()->toDateString(),
        ]);

        // Aggregate totals for the panel defense slide
        $totals = DB::selectOne("
            SELECT
                COUNT(*)                                                    AS total_scored,
                COUNT(*) FILTER (WHERE doctor_overrode = false)            AS total_accepted,
                COUNT(*) FILTER (WHERE doctor_overrode = true)             AS total_overridden,
                ROUND(AVG(ai_score), 2)                                    AS overall_avg_score,
                ROUND(
                    COUNT(*) FILTER (WHERE doctor_overrode = false)::numeric
                    / NULLIF(COUNT(*), 0) * 100, 2
                )                                                          AS overall_acceptance_rate
            FROM ai_triage_scores
        ");

        return response()->json([
            'data'    => $data,
            'summary' => $totals,
        ]);
    }

    /**
     * GET /api/v1/analytics/top-diagnoses
     * Top ICD-10 diagnoses — for DOH reporting and thesis.
     */
    public function topDiagnoses(Request $request): JsonResponse
    {
        $this->requireAnalyticsRole($request);

        $request->validate([
            'from'  => ['required', 'date'],
            'to'    => ['required', 'date'],
            'limit' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $limit = $request->integer('limit', 10);

        $data = DB::select("
            SELECT
                primary_diagnosis_code,
                primary_diagnosis_label,
                COUNT(*)            AS frequency,
                COUNT(*) FILTER (WHERE is_finalized = true) AS finalized_count
            FROM telemedicine_session_notes
            WHERE primary_diagnosis_code IS NOT NULL
              AND created_at BETWEEN ? AND ?
            GROUP BY primary_diagnosis_code, primary_diagnosis_label
            ORDER BY frequency DESC
            LIMIT ?
        ", [$request->from, $request->to . ' 23:59:59', $limit]);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/analytics/overview
     * Single payload for thesis defense demo dashboard.
     */
    public function overview(Request $request): JsonResponse
    {
        $this->requireAnalyticsRole($request);

        $request->validate([
            'rhu_id' => ['required', 'integer'],
        ]);

        $rhuId = $request->integer('rhu_id');
        $today = now()->toDateString();
        $month = now()->startOfMonth()->toDateString();

        return response()->json([
            'generated_at' => now()->toIso8601String(),

            'today' => [
                'queue' => DB::selectOne("
                    SELECT
                        COUNT(*)                                         AS total,
                        COUNT(*) FILTER (WHERE status = 'waiting')      AS waiting,
                        COUNT(*) FILTER (WHERE status = 'completed')    AS completed,
                        ROUND(AVG(wait_time_minutes))                   AS avg_wait_minutes
                    FROM queue_tickets
                    WHERE rhu_id = ? AND DATE(issued_at) = ?
                ", [$rhuId, $today]),

                'telemedicine' => DB::selectOne("
                    SELECT
                        COUNT(*)                                              AS total,
                        COUNT(*) FILTER (WHERE status = 'pending')           AS pending,
                        COUNT(*) FILTER (WHERE status = 'completed')         AS completed,
                        COUNT(*) FILTER (WHERE urgency_level = 'emergency')  AS emergency
                    FROM telemedicine_requests
                    WHERE rhu_id = ? AND DATE(created_at) = ?
                ", [$rhuId, $today]),

                'prescriptions' => DB::selectOne("
                    SELECT COUNT(*) AS issued_today
                    FROM prescriptions
                    WHERE rhu_id = ? AND DATE(prescription_date) = ?
                ", [$rhuId, $today]),

                'referrals' => DB::selectOne("
                    SELECT
                        COUNT(*) FILTER (WHERE status = 'pending')  AS pending,
                        COUNT(*) FILTER (WHERE is_urgent = true)    AS urgent
                    FROM referrals
                    WHERE DATE(created_at) = ?
                ", [$today]),
            ],

            'this_month' => [
                'total_consultations' => DB::selectOne("
                    SELECT COUNT(*) AS count FROM consultations
                    WHERE DATE(consultation_date) >= ?
                ", [$month])?->count ?? 0,

                'total_prescriptions' => DB::selectOne("
                    SELECT COUNT(*) AS count FROM prescriptions
                    WHERE rhu_id = ? AND DATE(prescription_date) >= ?
                ", [$rhuId, $month])?->count ?? 0,

                'ai_triage_count' => DB::selectOne("
                    SELECT COUNT(*) AS count FROM ai_triage_scores
                    WHERE DATE(created_at) >= ?
                ", [$month])?->count ?? 0,
            ],

            'ai_summary' => DB::selectOne("
                SELECT
                    COUNT(*)                                                    AS total_scored,
                    ROUND(AVG(ai_score), 1)                                    AS avg_score,
                    ROUND(
                        COUNT(*) FILTER (WHERE doctor_overrode = false)::numeric
                        / NULLIF(COUNT(*), 0) * 100, 1
                    )                                                          AS acceptance_rate_pct
                FROM ai_triage_scores
            "),
        ]);
    }

    private function requireAnalyticsRole(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(['mho', 'super_admin', 'staff_admin']),
            403,
            'Analytics access is restricted to authorized staff.'
        );
    }
}