<?php
// database/migrations/2026_06_30_090000_add_two_level_telemedicine_workflow.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-level telemedicine workflow.
 *
 * Level 1 (nurse / midwife / head_nurse / staff): screen, collect vitals, endorse.
 * Level 2 (doctor / mho):  review screening, schedule session, conduct consultation.
 *
 * Changes:
 *  1. Expand the status enum check constraint to include `screening` and
 *     `endorsed_to_doctor` intermediate statuses.
 *  2. Add vital-sign columns collected at screening time.
 *  3. Add `endorsed_to` (FK → users) and `endorsed_at` for endorsement tracking.
 *
 * All additions use Schema::hasColumn guards so this migration is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telemedicine_requests')) {
            return;
        }

        // 1. Expand the status check constraint.
        // Laravel's enum() in PostgreSQL creates a check constraint named
        // {table}_{column}_check. Drop and re-create with the extended list.
        DB::statement("
            ALTER TABLE telemedicine_requests
            DROP CONSTRAINT IF EXISTS telemedicine_requests_status_check
        ");

        DB::statement("
            ALTER TABLE telemedicine_requests
            ADD CONSTRAINT telemedicine_requests_status_check CHECK (status IN (
                'pending',
                'screening',
                'screened',
                'endorsed_to_doctor',
                'scheduled',
                'rejected',
                'cancelled',
                'completed'
            ))
        ");

        // 2. Add vital-sign and endorsement columns.
        Schema::table('telemedicine_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('telemedicine_requests', 'vital_temperature')) {
                $table->string('vital_temperature', 20)->nullable()->after('screening_notes');
            }

            if (!Schema::hasColumn('telemedicine_requests', 'vital_bp')) {
                $table->string('vital_bp', 30)->nullable()->after('vital_temperature');
            }

            if (!Schema::hasColumn('telemedicine_requests', 'vital_heart_rate')) {
                $table->string('vital_heart_rate', 20)->nullable()->after('vital_bp');
            }

            if (!Schema::hasColumn('telemedicine_requests', 'vital_respiratory_rate')) {
                $table->string('vital_respiratory_rate', 20)->nullable()->after('vital_heart_rate');
            }

            if (!Schema::hasColumn('telemedicine_requests', 'endorsed_to')) {
                $table->unsignedBigInteger('endorsed_to')->nullable()->after('vital_respiratory_rate')
                    ->comment('Doctor/MHO user_id this request is endorsed to');
                $table->foreign('endorsed_to')->references('user_id')->on('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('telemedicine_requests', 'endorsed_at')) {
                $table->timestamp('endorsed_at')->nullable()->after('endorsed_to');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('telemedicine_requests')) {
            return;
        }

        // Restore the original check constraint.
        DB::statement("
            ALTER TABLE telemedicine_requests
            DROP CONSTRAINT IF EXISTS telemedicine_requests_status_check
        ");

        DB::statement("
            ALTER TABLE telemedicine_requests
            ADD CONSTRAINT telemedicine_requests_status_check CHECK (status IN (
                'pending', 'screened', 'scheduled', 'rejected', 'cancelled', 'completed'
            ))
        ");

        Schema::table('telemedicine_requests', function (Blueprint $table) {
            if (Schema::hasColumn('telemedicine_requests', 'endorsed_to')) {
                $table->dropForeign(['endorsed_to']);
                $table->dropColumn('endorsed_to');
            }

            foreach ([
                'vital_temperature',
                'vital_bp',
                'vital_heart_rate',
                'vital_respiratory_rate',
                'endorsed_at',
            ] as $column) {
                if (Schema::hasColumn('telemedicine_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
