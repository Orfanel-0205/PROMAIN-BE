<?php
// database/migrations/2026_04_22_090001_add_performance_and_correctness_indexes.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Convert all JSON columns to JSONB for GIN indexability
        DB::statement('ALTER TABLE telemedicine_requests
            ALTER COLUMN symptoms TYPE jsonb USING symptoms::jsonb');
        DB::statement('ALTER TABLE telemedicine_session_notes
            ALTER COLUMN medications TYPE jsonb USING medications::jsonb');

        // GIN indexes on JSONB columns you query with @> operator
        DB::statement('CREATE INDEX IF NOT EXISTS idx_gin_tele_symptoms
            ON telemedicine_requests USING gin(symptoms)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_gin_session_medications
            ON telemedicine_session_notes USING gin(medications)');

        // Composite partial indexes — terminal states excluded
        DB::statement('CREATE INDEX IF NOT EXISTS idx_queue_active_workflow
            ON queue_tickets (rhu_id, service_type, priority_score DESC, issued_at)
            WHERE status IN (\'waiting\', \'called\', \'in_service\') AND deleted_at IS NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_tele_requests_dashboard
            ON telemedicine_requests (rhu_id, status, urgency_level, created_at DESC)
            WHERE deleted_at IS NULL');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_notifications_unread
            ON notifications (notifiable_type, notifiable_id, created_at DESC)
            WHERE read_at IS NULL');

        // Missing composite indexes on existing tables - using raw for IF NOT EXISTS
        DB::statement('CREATE INDEX IF NOT EXISTS users_role_id_account_status_index ON users (role_id, account_status)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_barangay_id_index ON users (barangay_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS resident_profiles_barangay_id_index ON resident_profiles (barangay_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS consultations_user_id_status_index ON consultations (user_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS consultations_consultation_date_attended_by_index ON consultations (consultation_date, attended_by)');
        DB::statement('CREATE INDEX IF NOT EXISTS medical_reports_user_id_consultation_id_index ON medical_reports (user_id, consultation_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS medical_reports_report_type_index ON medical_reports (report_type)');

        // Add missing soft-delete columns to tables that need them
        // consultations and medical_reports should have soft deletes for clinical data
        if (!Schema::hasColumn('consultations', 'deleted_at')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (!Schema::hasColumn('medical_reports', 'deleted_at')) {
            Schema::table('medical_reports', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_gin_tele_symptoms');
        DB::statement('DROP INDEX IF EXISTS idx_gin_session_medications');
        DB::statement('DROP INDEX IF EXISTS idx_queue_active_workflow');
        DB::statement('DROP INDEX IF EXISTS idx_tele_requests_dashboard');
        DB::statement('DROP INDEX IF EXISTS idx_notifications_unread');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id', 'account_status']);
            $table->dropIndex(['barangay_id']);
        });

        Schema::table('resident_profiles', function (Blueprint $table) {
            $table->dropIndex(['barangay_id']);
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['consultation_date', 'attended_by']);
        });

        Schema::table('medical_reports', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'consultation_id']);
            $table->dropIndex(['report_type']);
        });
    }
};
