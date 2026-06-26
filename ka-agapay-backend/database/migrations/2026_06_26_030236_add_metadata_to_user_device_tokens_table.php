<?php
// database/migrations/2026_06_26_000002_add_metadata_to_user_device_tokens_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::table('user_device_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('user_device_tokens', 'app_version')) {
                $table->string('app_version', 40)->nullable()->after('device_name');
            }

            if (!Schema::hasColumn('user_device_tokens', 'channel_id')) {
                $table->string('channel_id', 80)->nullable()->after('app_version');
            }

            if (!Schema::hasColumn('user_device_tokens', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('last_seen_at');
            }

            if (!Schema::hasColumn('user_device_tokens', 'failure_reason')) {
                $table->string('failure_reason', 120)->nullable()->after('failed_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::table('user_device_tokens', function (Blueprint $table) {
            foreach ([
                'failure_reason',
                'failed_at',
                'channel_id',
                'app_version',
            ] as $column) {
                if (Schema::hasColumn('user_device_tokens', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};