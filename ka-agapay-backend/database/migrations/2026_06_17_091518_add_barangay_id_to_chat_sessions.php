<?php
// database/migrations/2026_06_17_180000_add_barangay_id_to_chat_sessions.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_sessions')) {
            return;
        }

        Schema::table('chat_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_sessions', 'barangay_id')) {
                $table->foreignId('barangay_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('barangays', 'barangay_id')
                    ->nullOnDelete();

                $table->index(['barangay_id', 'audience', 'status'], 'chat_sessions_barangay_audience_status_idx');
            }
        });

        /*
         * Backfill from users.barangay_id when available.
         */
        if (
            Schema::hasTable('users') &&
            Schema::hasColumn('users', 'barangay_id') &&
            Schema::hasColumn('users', 'user_id')
        ) {
            DB::statement("
                UPDATE chat_sessions cs
                SET barangay_id = u.barangay_id
                FROM users u
                WHERE cs.user_id = u.user_id
                  AND cs.barangay_id IS NULL
                  AND u.barangay_id IS NOT NULL
            ");
        }

        /*
         * Backfill from resident_profiles.user_id → barangay_id.
         * This is important because many resident accounts store barangay in resident_profiles,
         * not directly in users.
         */
        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            DB::statement("
                UPDATE chat_sessions cs
                SET barangay_id = rp.barangay_id
                FROM resident_profiles rp
                WHERE cs.user_id = rp.user_id
                  AND cs.barangay_id IS NULL
                  AND rp.barangay_id IS NOT NULL
            ");
        }

        /*
         * Backfill from legacy chat_logs when chat_sessions.user_id is missing
         * but chat_logs has the same session_token and user_id.
         */
        if (
            Schema::hasTable('chat_logs') &&
            Schema::hasColumn('chat_logs', 'session_token') &&
            Schema::hasColumn('chat_logs', 'user_id') &&
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            DB::statement("
                UPDATE chat_sessions cs
                SET barangay_id = rp.barangay_id
                FROM chat_logs cl
                INNER JOIN resident_profiles rp
                    ON rp.user_id = cl.user_id
                WHERE cs.session_token = cl.session_token
                  AND cs.barangay_id IS NULL
                  AND rp.barangay_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_sessions')) {
            return;
        }

        Schema::table('chat_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('chat_sessions', 'barangay_id')) {
                $table->dropForeign(['barangay_id']);
                $table->dropColumn('barangay_id');
            }
        });
    }
};