<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 0.5 & A.1 Fix 1 - Rename JSON columns to JSONB
        // Convert json columns to jsonb (assumes tables exist from previous migrations)
        if (Schema::hasTable('telemedicine_requests') && Schema::hasColumn('telemedicine_requests', 'symptoms')) {
            DB::statement('ALTER TABLE telemedicine_requests ALTER COLUMN symptoms TYPE jsonb USING symptoms::jsonb');
        }
        if (Schema::hasTable('telemedicine_sessions_notes') && Schema::hasColumn('telemedicine_sessions_notes', 'medications')) {
            DB::statement('ALTER TABLE telemedicine_sessions_notes ALTER COLUMN medications TYPE jsonb USING medications::jsonb');
        }
        if (Schema::hasTable('queue_logs') && Schema::hasColumn('queue_logs', 'metadata')) {
            DB::statement('ALTER TABLE queue_logs ALTER COLUMN metadata TYPE jsonb USING metadata::jsonb');
        }
        if (Schema::hasTable('telemedicine_logs') && Schema::hasColumn('telemedicine_logs', 'metadata')) {
            DB::statement('ALTER TABLE telemedicine_logs ALTER COLUMN metadata TYPE jsonb USING metadata::jsonb');
        }

        // A.1 Fix 2 - Add GIN Index on JSONB columns you query
        if (Schema::hasTable('telemedicine_requests') && Schema::hasColumn('telemedicine_requests', 'symptoms')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_telemedicine_requests_symptoms ON telemedicine_requests USING gin(symptoms)');
        }
        if (Schema::hasTable('telemedicine_sessions_notes') && Schema::hasColumn('telemedicine_sessions_notes', 'medications')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_session_notes_medications ON telemedicine_sessions_notes USING gin(medications)');
        }

        // 0.7 Critical Missing Indexes & A.1 Fix 3 - Composite Indexes
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role_id', 'account_status']);
            $table->index('barangay_id');
        });

        Schema::table('resident_profiles', function (Blueprint $table) {
            $table->index('barangay_id');
        });

        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->index(['user_id', 'status']);
                $table->index('appointment_date');
                $table->index('handled_by');
            });
        }

        Schema::table('consultations', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
            $table->index('consultation_date');
            $table->index('attended_by');
        });

        if (Schema::hasTable('medical_reports')) {
            Schema::table('medical_reports', function (Blueprint $table) {
                $table->index(['user_id', 'consultation_id']);
                $table->index('report_type');
            });
        }

        if (Schema::hasTable('queue_tickets')) {
            Schema::table('queue_tickets', function (Blueprint $table) {
                $table->index(['rhu_id', 'service_type', 'status']);
                $table->index(['resident_profile_id', 'status']);
                $table->index('priority_score');
            });

            // A.1 Fix 3 & A.4 Partial Indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_queue_workflow ON queue_tickets (rhu_id, service_type, status, priority_score DESC, issued_at)');
            DB::statement("CREATE INDEX IF NOT EXISTS idx_queue_active ON queue_tickets (rhu_id, status, priority_score DESC) WHERE status IN ('waiting', 'called', 'in_service') AND deleted_at IS NULL");
        }

        if (Schema::hasTable('telemedicine_requests')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_telemedicine_dashboard ON telemedicine_requests (rhu_id, status, urgency_level, created_at DESC)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversal of these changes is complex and usually requires specific DROP statements
        // Omitting for brevity
    }
};
