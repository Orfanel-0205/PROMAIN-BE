<?php
// database/migrations/2026_06_18_000003_add_coordinate_indexes_to_barangays_and_heatmaps.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('barangays')) {
            Schema::table('barangays', function (Blueprint $table) {
                if (!Schema::hasColumn('barangays', 'coordinate_source')) {
                    $table->string('coordinate_source', 80)
                        ->nullable()
                        ->after('longitude');
                }
            });

            $this->createIndexIfPossible(
                'barangays',
                'idx_barangays_coordinates',
                ['latitude', 'longitude']
            );

            $this->createIndexIfPossible(
                'barangays',
                'idx_barangays_coordinate_source',
                ['coordinate_source']
            );
        }

        if (Schema::hasTable('barangay_heatmaps')) {
            $this->createIndexIfPossible(
                'barangay_heatmaps',
                'idx_bh_case_map_lookup',
                ['log_date', 'active_cases', 'barangay_id']
            );

            $this->createIndexIfPossible(
                'barangay_heatmaps',
                'idx_bh_risk_case_lookup',
                ['risk_level', 'active_cases', 'log_date']
            );
        }
    }

    public function down(): void
    {
        $this->dropIndexIfPossible('barangay_heatmaps', 'idx_bh_risk_case_lookup');
        $this->dropIndexIfPossible('barangay_heatmaps', 'idx_bh_case_map_lookup');
        $this->dropIndexIfPossible('barangays', 'idx_barangays_coordinate_source');
        $this->dropIndexIfPossible('barangays', 'idx_barangays_coordinates');

        if (
            Schema::hasTable('barangays') &&
            Schema::hasColumn('barangays', 'coordinate_source')
        ) {
            Schema::table('barangays', function (Blueprint $table) {
                $table->dropColumn('coordinate_source');
            });
        }
    }

    private function createIndexIfPossible(string $table, string $index, array $columns): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $columnList = implode(', ', array_map(fn ($column) => '"' . $column . '"', $columns));
            DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON {$table} ({$columnList})");
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $index) {
                $blueprint->index($columns, $index);
            });
        } catch (Throwable) {
            // Index may already exist. Safe to ignore.
        }
    }

    private function dropIndexIfPossible(string $table, string $index): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'pgsql') {
                DB::statement("DROP INDEX IF EXISTS {$index}");
                return;
            }

            DB::statement("DROP INDEX {$index} ON {$table}");
        } catch (Throwable) {
            // Index may not exist. Safe to ignore.
        }
    }
};