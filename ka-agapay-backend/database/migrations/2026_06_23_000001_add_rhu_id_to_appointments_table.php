<?php
// database/migrations/2026_06_23_000001_add_rhu_id_to_appointments_table.php
//
// CRITICAL FIX: the appointments table had no rhu_id column, so the RHU the
// patient selected during online booking was silently dropped (the controller
// guarded the write with Schema::hasColumn and skipped it). This adds the
// column so an appointment is tied to RHU 1 or RHU 2 and can route to the
// correct queue.
//
// rhu_id references barangays.barangay_id (same convention as queue_tickets).
// Idempotent + Postgres compatible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('appointments') || Schema::hasColumn('appointments', 'rhu_id')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            // Nullable so existing rows remain valid; FK to barangays(barangay_id).
            if (Schema::hasTable('barangays')) {
                $table->foreignId('rhu_id')
                    ->nullable()
                    ->constrained('barangays', 'barangay_id')
                    ->nullOnDelete();
            } else {
                $table->unsignedBigInteger('rhu_id')->nullable();
            }

            $table->index(['rhu_id', 'status']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('appointments') || !Schema::hasColumn('appointments', 'rhu_id')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            // Drop FK first if it exists (ignore failure on drivers without it).
            try {
                $table->dropForeign(['rhu_id']);
            } catch (\Throwable) {
                // no-op
            }

            try {
                $table->dropIndex(['rhu_id', 'status']);
            } catch (\Throwable) {
                // no-op
            }

            $table->dropColumn('rhu_id');
        });
    }
};
