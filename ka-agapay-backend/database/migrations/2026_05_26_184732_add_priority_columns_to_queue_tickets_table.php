<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('queue_tickets', 'rhu_id')) {
                $table->unsignedInteger('rhu_id')->default(1)->after('id');
            }
            if (!Schema::hasColumn('queue_tickets', 'is_emergency')) {
                $table->boolean('is_emergency')->default(false);
            }
            if (!Schema::hasColumn('queue_tickets', 'wait_time_minutes')) {
                $table->unsignedInteger('wait_time_minutes')->nullable();
            }
            if (!Schema::hasColumn('queue_tickets', 'priority_score')) {
                $table->unsignedTinyInteger('priority_score')->default(0);
            }
            if (!Schema::hasColumn('queue_tickets', 'priority_category')) {
                $table->string('priority_category', 15)->default('Low');
            }
            if (!Schema::hasColumn('queue_tickets', 'queue_type')) {
                $table->string('queue_type', 20)->default('walk_in');
            }

            // Indexes for ORDER BY performance
            $table->index(['rhu_id', 'status', 'issued_at'], 'qt_rhu_status_issued_idx');
            $table->index(['priority_score', 'issued_at'],   'qt_score_issued_idx');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'rhu_id', 'is_emergency', 'wait_time_minutes',
                'priority_score', 'priority_category', 'queue_type',
            ]);
        });
    }
};