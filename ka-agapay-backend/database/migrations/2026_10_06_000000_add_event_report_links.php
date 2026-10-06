<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an event report is built from.
 *
 * After an event the RHU accounts for it: who registered, who actually came,
 * and what was handed out. Until now none of that could be recorded:
 * attendance could not be marked, and a stock-out could not say it was for
 * an event.
 *
 * - inventory_transactions.event_id: a stock-out may name the event it was
 *   for (optional; every other stock-out is unchanged).
 * - event_registrations.attendance_marked_by / _at: who marked a resident
 *   attended or no-show, and when, for the audit trail.
 * - events.report_generated_at: when the event ended and its report was
 *   announced to staff, so that happens once.
 *
 * All nullable and additive: existing rows and screens are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_transactions', 'event_id')) {
                $table->unsignedBigInteger('event_id')->nullable()->index();
            }
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('event_registrations', 'attendance_marked_by')) {
                $table->unsignedBigInteger('attendance_marked_by')->nullable();
            }

            if (!Schema::hasColumn('event_registrations', 'attendance_marked_at')) {
                $table->timestamp('attendance_marked_at')->nullable();
            }
        });

        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'report_generated_at')) {
                $table->timestamp('report_generated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_transactions', 'event_id')) {
                $table->dropIndex(['event_id']);
                $table->dropColumn('event_id');
            }
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            foreach (['attendance_marked_by', 'attendance_marked_at'] as $column) {
                if (Schema::hasColumn('event_registrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'report_generated_at')) {
                $table->dropColumn('report_generated_at');
            }
        });
    }
};
