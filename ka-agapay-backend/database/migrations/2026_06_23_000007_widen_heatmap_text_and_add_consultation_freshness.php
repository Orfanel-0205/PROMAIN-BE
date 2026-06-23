<?php
// database/migrations/2026_06_23_000007_widen_heatmap_text_and_add_consultation_freshness.php
//
// Two additive, idempotent fixes:
//  1. Widen heatmap "disease_type" / "top_case_type" columns to TEXT so a long
//     diagnosis/complaint label never triggers a 500 (varchar(100) overflow).
//  2. Add consultation heatmap freshness fields (heatmap_posted_at,
//     heatmap_signal_expires_at) — a 3-hour "fresh signal" window for realtime
//     analytics. This is visibility/freshness only; NOTHING is deleted and
//     historical reports always include completed consultations.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1) Long-text safety for heatmap label columns.
        $this->toText($driver, 'barangay_heatmaps', 'disease_type');
        $this->toText($driver, 'barangay_heatmaps', 'top_case_type');
        $this->toText($driver, 'heatmap_alerts', 'disease_type');
        $this->toText($driver, 'heatmap_alerts', 'trigger_message'); // already text — harmless re-assert

        // 2) Consultation heatmap freshness window.
        if (Schema::hasTable('consultations')) {
            Schema::table('consultations', function (Blueprint $table) {
                if (!Schema::hasColumn('consultations', 'heatmap_posted_at')) {
                    $table->timestamp('heatmap_posted_at')->nullable();
                }

                if (!Schema::hasColumn('consultations', 'heatmap_signal_expires_at')) {
                    $table->timestamp('heatmap_signal_expires_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        // Revert the widened columns to varchar(100). Safe because the app never
        // stored >100 chars before this migration.
        $this->toVarchar($driver, 'barangay_heatmaps', 'disease_type', 100, false);
        $this->toVarchar($driver, 'barangay_heatmaps', 'top_case_type', 100, true);
        $this->toVarchar($driver, 'heatmap_alerts', 'disease_type', 100, false);

        if (Schema::hasTable('consultations')) {
            Schema::table('consultations', function (Blueprint $table) {
                foreach (['heatmap_signal_expires_at', 'heatmap_posted_at'] as $column) {
                    if (Schema::hasColumn('consultations', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function toText(string $driver, string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE TEXT");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} TEXT NULL");
        }
        // sqlite: TEXT affinity already; no-op.
    }

    private function toVarchar(string $driver, string $table, string $column, int $len, bool $nullable): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE VARCHAR({$len})");
        } elseif ($driver === 'mysql') {
            $null = $nullable ? 'NULL' : 'NOT NULL';
            DB::statement("ALTER TABLE {$table} MODIFY {$column} VARCHAR({$len}) {$null}");
        }
    }
};
