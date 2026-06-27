<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe completed-record board visibility / archive policy.
 *
 * Adds non-destructive bookkeeping columns so completed appointments and
 * telemedicine requests can be hidden from the ACTIVE board after a grace
 * period while remaining fully queryable for Completed/History views and
 * reports. No clinical data is ever deleted.
 *
 * All adds are guarded with Schema::hasColumn so this is safe to re-run and
 * never duplicates columns that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                if (!Schema::hasColumn('appointments', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->index();
                }

                if (!Schema::hasColumn('appointments', 'board_visible_until')) {
                    $table->timestamp('board_visible_until')->nullable()->index();
                }

                if (!Schema::hasColumn('appointments', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->index();
                }

                if (!Schema::hasColumn('appointments', 'archive_reason')) {
                    $table->string('archive_reason', 255)->nullable();
                }

                if (!Schema::hasColumn('appointments', 'has_pending_follow_up')) {
                    $table->boolean('has_pending_follow_up')->default(false);
                }
            });
        }

        if (Schema::hasTable('telemedicine_requests')) {
            Schema::table('telemedicine_requests', function (Blueprint $table) {
                if (!Schema::hasColumn('telemedicine_requests', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->index();
                }

                if (!Schema::hasColumn('telemedicine_requests', 'board_visible_until')) {
                    $table->timestamp('board_visible_until')->nullable()->index();
                }

                if (!Schema::hasColumn('telemedicine_requests', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->index();
                }

                if (!Schema::hasColumn('telemedicine_requests', 'archive_reason')) {
                    $table->string('archive_reason', 255)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: keep the bookkeeping columns on rollback
        // so historical/report data is never lost. Drop only if explicitly needed.
        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                foreach ([
                    'completed_at',
                    'board_visible_until',
                    'archived_at',
                    'archive_reason',
                    'has_pending_follow_up',
                ] as $column) {
                    if (Schema::hasColumn('appointments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('telemedicine_requests')) {
            Schema::table('telemedicine_requests', function (Blueprint $table) {
                foreach ([
                    'completed_at',
                    'board_visible_until',
                    'archived_at',
                    'archive_reason',
                ] as $column) {
                    if (Schema::hasColumn('telemedicine_requests', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
