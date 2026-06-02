<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Creates PostgreSQL views for heatmap analytics.
     *
     * vw_barangay_heatmap_summary:
     *   Aggregates consultation diagnoses per barangay with real-time queue density
     *   and resident population for incidence-rate computation.
     *
     * vw_queue_congestion_hourly:
     *   Hourly queue ticket distribution for congestion trend analysis
     *   and optimal staff allocation recommendations.
     */
    public function up(): void
    {
        // ── Barangay Heatmap Summary ─────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW vw_barangay_heatmap_summary AS
            SELECT
                b.barangay_id,
                b.name                          AS barangay,
                b.latitude,
                b.longitude,
                b.population,
                COUNT(DISTINCT c.id)            AS total_consultations,
                COUNT(DISTINCT CASE
                    WHEN c.consultation_date >= CURRENT_DATE - INTERVAL '7 days'
                    THEN c.id
                END)                            AS cases_last_7_days,
                COUNT(DISTINCT CASE
                    WHEN c.consultation_date >= CURRENT_DATE - INTERVAL '30 days'
                    THEN c.id
                END)                            AS cases_last_30_days,
                COALESCE(q.active_waiting, 0)   AS queue_density,
                COUNT(DISTINCT tr.id)           AS telemedicine_requests
            FROM barangays b
            LEFT JOIN resident_profiles rp
                ON rp.barangay_id = b.barangay_id
            LEFT JOIN consultations c
                ON c.user_id = rp.user_id
            LEFT JOIN telemedicine_requests tr
                ON tr.resident_profile_id = rp.id
                AND tr.deleted_at IS NULL
            LEFT JOIN LATERAL (
                SELECT COUNT(*) AS active_waiting
                FROM queue_tickets qt
                INNER JOIN resident_profiles rp2
                    ON rp2.id = qt.resident_profile_id
                WHERE rp2.barangay_id = b.barangay_id
                  AND qt.status = 'waiting'
                  AND DATE(qt.issued_at) = CURRENT_DATE
                  AND qt.deleted_at IS NULL
            ) q ON true
            GROUP BY b.barangay_id, b.name, b.latitude, b.longitude, b.population, q.active_waiting
            ORDER BY total_consultations DESC
        ");

        // ── Hourly Queue Congestion ──────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW vw_queue_congestion_hourly AS
            SELECT
                rhu_id,
                DATE(issued_at)                                      AS queue_date,
                EXTRACT(HOUR FROM issued_at)::int                    AS hour_of_day,
                COUNT(*)                                             AS tickets_issued,
                COUNT(*) FILTER (WHERE status = 'waiting')           AS still_waiting,
                COUNT(*) FILTER (WHERE status = 'completed')         AS completed,
                COUNT(*) FILTER (WHERE is_emergency = true)          AS emergency_count,
                ROUND(AVG(wait_time_minutes))                        AS avg_wait_minutes,
                COUNT(*) FILTER (WHERE priority_category != 'regular') AS priority_tickets
            FROM queue_tickets
            WHERE deleted_at IS NULL
            GROUP BY rhu_id, DATE(issued_at), EXTRACT(HOUR FROM issued_at)
            ORDER BY queue_date DESC, hour_of_day
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS vw_barangay_heatmap_summary');
        DB::statement('DROP VIEW IF EXISTS vw_queue_congestion_hourly');
    }
};
