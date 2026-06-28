<?php
// database/migrations/2026_06_29_000000_add_rhu_id_to_consultations_table.php
//
// Adds rhu_id (facility 1 = RHU 1, 2 = RHU 2) to consultations so the Web Admin
// can scope/filter consultation records by RHU. New consultations inherit the
// appointment's rhu_id; existing rows are backfilled from their appointment.
// Idempotent + Postgres compatible. Nullable so legacy rows are not broken.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('consultations')) {
            return;
        }

        if (!Schema::hasColumn('consultations', 'rhu_id')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->unsignedSmallInteger('rhu_id')->nullable()->index();
            });
        }

        // Backfill from the linked appointment's rhu_id where available.
        if (
            Schema::hasColumn('consultations', 'appointment_id')
            && Schema::hasTable('appointments')
            && Schema::hasColumn('appointments', 'rhu_id')
        ) {
            try {
                DB::statement('
                    UPDATE consultations c
                    SET rhu_id = a.rhu_id
                    FROM appointments a
                    WHERE c.appointment_id = a.id
                      AND c.rhu_id IS NULL
                      AND a.rhu_id IS NOT NULL
                ');
            } catch (\Throwable) {
                // Non-fatal: column still exists; rhu_id can be derived at read time.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('consultations') && Schema::hasColumn('consultations', 'rhu_id')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->dropColumn('rhu_id');
            });
        }
    }
};
