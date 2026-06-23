<?php
// database/migrations/2026_06_23_000006_add_visit_tracking_to_consultations_table.php
//
// Slice B1 — onsite consultation visit tracking. Purely ADDITIVE + idempotent.
// - first_attended_at / first_attended_by: when staff first opened/worked the
//   SOAP at the desk (and who).
// - draft_saved_at: last time the SOAP draft was saved without completing.
// - itr_snapshot: a JSON copy of the patient's ITR/profile at first-attend, kept
//   for record consistency (display still uses the live profile when present).
//
// Nothing here deletes data or alters existing columns. The existing
// started_at / completed_at / status columns are untouched.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('consultations')) {
            return;
        }

        Schema::table('consultations', function (Blueprint $table) {
            if (!Schema::hasColumn('consultations', 'first_attended_at')) {
                $table->timestamp('first_attended_at')->nullable()->after('completed_at');
            }

            if (!Schema::hasColumn('consultations', 'first_attended_by')) {
                if (Schema::hasTable('users')) {
                    $table->foreignId('first_attended_by')
                        ->nullable()
                        ->after('first_attended_at')
                        ->constrained('users', 'user_id')
                        ->nullOnDelete();
                } else {
                    $table->unsignedBigInteger('first_attended_by')->nullable()->after('first_attended_at');
                }
            }

            if (!Schema::hasColumn('consultations', 'draft_saved_at')) {
                $table->timestamp('draft_saved_at')->nullable()->after('first_attended_by');
            }

            if (!Schema::hasColumn('consultations', 'itr_snapshot')) {
                $table->json('itr_snapshot')->nullable()->after('draft_saved_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('consultations')) {
            return;
        }

        Schema::table('consultations', function (Blueprint $table) {
            if (Schema::hasColumn('consultations', 'first_attended_by')) {
                try {
                    $table->dropForeign(['first_attended_by']);
                } catch (\Throwable) {
                    // no-op
                }
            }

            foreach (['itr_snapshot', 'draft_saved_at', 'first_attended_by', 'first_attended_at'] as $column) {
                if (Schema::hasColumn('consultations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
