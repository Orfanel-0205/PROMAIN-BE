<?php
// database/migrations/2026_06_27_000001_expand_appointment_reason_text_columns.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('appointments')) {
            return;
        }

        foreach ([
            'purpose',
            'reason',
            'chief_complaint',
            'complaint',
            'symptoms',
            'notes',
            'rejection_reason',
        ] as $column) {
            if (Schema::hasColumn('appointments', $column)) {
                $this->convertColumnToText('appointments', $column);
            }
        }
    }

    public function down(): void
    {
        // Intentionally no destructive rollback.
        // Converting TEXT back to VARCHAR could truncate resident complaints.
    }

    private function convertColumnToText(string $table, string $column): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE TEXT");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY {$column} TEXT NULL");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite does not need a type conversion for this use case.
            return;
        }
    }
};