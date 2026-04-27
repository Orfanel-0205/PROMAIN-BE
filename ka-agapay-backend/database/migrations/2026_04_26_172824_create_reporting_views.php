<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Queue performance by service type ─────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW vw_queue_performance AS
            SELECT
                rhu_id,
                service_type,
                DATE(issued_at)                                          AS queue_date,
                COUNT(*)                                                 AS total_tickets,
                COUNT(*) FILTER (WHERE status = 'completed')            AS completed,
                COUNT(*) FILTER (WHERE status = 'no_show')              AS no_shows,
                COUNT(*) FILTER (WHERE status = 'cancelled')            AS cancelled,
                ROUND(AVG(wait_time_minutes))                           AS avg_wait_minutes,
                ROUND(AVG(service_time_minutes))                        AS avg_service_minutes,
                COUNT(*) FILTER (WHERE priority_category != 'regular')  AS priority_served,
                COUNT(*) FILTER (WHERE is_emergency = true)             AS emergency_count
            FROM queue_tickets
            WHERE deleted_at IS NULL
            GROUP BY rhu_id, service_type, DATE(issued_at)
        ");

        // ── Telemedicine daily summary ────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW vw_telemedicine_summary AS
            SELECT
                tr.rhu_id,
                DATE(tr.created_at)                                          AS request_date,
                COUNT(*)                                                     AS total_requests,
                COUNT(*) FILTER (WHERE tr.status = 'completed')             AS completed,
                COUNT(*) FILTER (WHERE tr.status = 'rejected')              AS rejected,
                COUNT(*) FILTER (WHERE tr.status = 'cancelled')             AS cancelled,
                COUNT(*) FILTER (WHERE tr.urgency_level = 'emergency')      AS emergency_count,
                COUNT(*) FILTER (WHERE tr.urgency_level = 'urgent')         AS urgent_count,
                COUNT(*) FILTER (WHERE tr.is_bhw_assisted = true)           AS bhw_assisted,
                ROUND(AVG(ts.actual_duration_minutes))                      AS avg_session_duration
            FROM telemedicine_requests tr
            LEFT JOIN telemedicine_sessions ts ON ts.request_id = tr.id
            WHERE tr.deleted_at IS NULL
            GROUP BY tr.rhu_id, DATE(tr.created_at)
        ");

        // ── Prescription summary ──────────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW vw_prescription_summary AS
            SELECT
                rhu_id,
                DATE(prescription_date)                                      AS rx_date,
                COUNT(*)                                                     AS total_issued,
                COUNT(*) FILTER (WHERE status = 'dispensed')                AS dispensed,
                COUNT(*) FILTER (WHERE status = 'expired')                  AS expired,
                COUNT(*) FILTER (WHERE status = 'voided')                   AS voided,
                COUNT(*) FILTER (WHERE has_controlled_substances = true)    AS controlled_substances
            FROM prescriptions
            WHERE deleted_at IS NULL
            GROUP BY rhu_id, DATE(prescription_date)
        ");

        // ── Barangay health profile ───────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW vw_barangay_health_profile AS
            SELECT
                b.barangay_id,
                b.name                          AS barangay,
                COUNT(DISTINCT rp.id)           AS registered_residents,
                COUNT(DISTINCT c.id)            AS total_consultations,
                COUNT(DISTINCT tr.id)           AS total_telemedicine_requests,
                COUNT(DISTINCT r.id)            AS total_referrals,
                COUNT(DISTINCT p.id)            AS total_prescriptions
            FROM barangays b
            LEFT JOIN resident_profiles rp ON rp.barangay_id = b.barangay_id
            LEFT JOIN consultations c      ON c.user_id = rp.user_id
            LEFT JOIN telemedicine_requests tr ON tr.resident_profile_id = rp.id
            LEFT JOIN referrals r          ON r.resident_profile_id = rp.id
            LEFT JOIN prescriptions p      ON p.resident_profile_id = rp.id
            GROUP BY b.barangay_id, b.name
            ORDER BY total_consultations DESC
        ");

        // ── AI triage accuracy ────────────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW vw_ai_triage_accuracy AS
            SELECT
                DATE(ats.created_at)                                        AS score_date,
                COUNT(*)                                                     AS total_scored,
                COUNT(*) FILTER (WHERE ats.doctor_overrode = false)        AS ai_accepted,
                COUNT(*) FILTER (WHERE ats.doctor_overrode = true)         AS doctor_overrode,
                ROUND(AVG(ats.ai_score), 2)                                AS avg_ai_score,
                ROUND(AVG(ats.confidence::numeric), 4)                     AS avg_confidence,
                ROUND(
                    COUNT(*) FILTER (WHERE ats.doctor_overrode = false)::numeric
                    / NULLIF(COUNT(*), 0) * 100, 2
                )                                                           AS acceptance_rate_pct
            FROM ai_triage_scores ats
            GROUP BY DATE(ats.created_at)
            ORDER BY score_date DESC
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS vw_queue_performance');
        DB::statement('DROP VIEW IF EXISTS vw_telemedicine_summary');
        DB::statement('DROP VIEW IF EXISTS vw_prescription_summary');
        DB::statement('DROP VIEW IF EXISTS vw_barangay_health_profile');
        DB::statement('DROP VIEW IF EXISTS vw_ai_triage_accuracy');
    }
};
