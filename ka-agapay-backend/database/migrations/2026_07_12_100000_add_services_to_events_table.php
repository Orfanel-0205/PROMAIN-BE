<?php
// database/migrations/2026_07_12_100000_add_services_to_events_table.php
//
// Event Creation redesign:
// 1. New `services` JSON column — the "RHU Service Offered" classification
//    (one event may belong to multiple RHU program services). Additive.
// 2. Widen `barangay_target` to TEXT — the field now stores either 'all' or a
//    comma-separated list of barangay names picked in the new multi-select.
//    Widening only (varchar → text): no data is changed or lost, and the
//    existing single-value rows ('all' / one barangay) keep working as-is.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        if (!Schema::hasColumn('events', 'services')) {
            Schema::table('events', function (Blueprint $table) {
                $table->json('services')->nullable();
            });
        }

        if (Schema::hasColumn('events', 'barangay_target')) {
            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('ALTER TABLE events ALTER COLUMN barangay_target TYPE TEXT');
                } elseif (DB::getDriverName() === 'mysql') {
                    DB::statement('ALTER TABLE events MODIFY barangay_target TEXT');
                }
            } catch (\Throwable $e) {
                // Non-fatal: single-barangay targeting keeps working with the
                // original column width; only very long multi-selections would
                // be affected. Logged for the deploy checklist.
                logger()->warning('[events migration] barangay_target widen skipped: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'services')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('services');
            });
        }
        // barangay_target stays TEXT on rollback — widening is harmless.
    }
};
