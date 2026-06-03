<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * This migration is made safe because activity_logs may already
         * have been created by an earlier migration:
         * 2026_04_22_090000_create_activity_logs_table.php
         */

        if (!Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('action', 100);
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();
            });

            return;
        }

        if (
            !Schema::hasColumn('activity_logs', 'user_id') ||
            !Schema::hasColumn('activity_logs', 'action') ||
            !Schema::hasColumn('activity_logs', 'metadata') ||
            !Schema::hasColumn('activity_logs', 'ip_address') ||
            !Schema::hasColumn('activity_logs', 'user_agent') ||
            !Schema::hasColumn('activity_logs', 'created_at') ||
            !Schema::hasColumn('activity_logs', 'updated_at')
        ) {
            Schema::table('activity_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('activity_logs', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->index();
                }

                if (!Schema::hasColumn('activity_logs', 'action')) {
                    $table->string('action', 100)->nullable();
                }

                if (!Schema::hasColumn('activity_logs', 'metadata')) {
                    $table->json('metadata')->nullable();
                }

                if (!Schema::hasColumn('activity_logs', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable();
                }

                if (!Schema::hasColumn('activity_logs', 'user_agent')) {
                    $table->string('user_agent', 500)->nullable();
                }

                if (
                    !Schema::hasColumn('activity_logs', 'created_at') &&
                    !Schema::hasColumn('activity_logs', 'updated_at')
                ) {
                    $table->timestamps();
                }
            });
        }
    }

    public function down(): void
    {
        /*
         * Do not drop activity_logs here because another earlier migration
         * may own the table.
         */
    }
};