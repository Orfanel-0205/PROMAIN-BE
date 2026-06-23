<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queue_tickets')) {
            Schema::table('queue_tickets', function (Blueprint $table) {
                if (!Schema::hasColumn('queue_tickets', 'consultation_id')) {
                    $table->unsignedBigInteger('consultation_id')->nullable()->after('appointment_id')->index();
                }

                if (!Schema::hasColumn('queue_tickets', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('service_ended_at')->index();
                }
            });
        }

        if (Schema::hasTable('follow_up_reminders')) {
            Schema::table('follow_up_reminders', function (Blueprint $table) {
                if (!Schema::hasColumn('follow_up_reminders', 'follow_up_type')) {
                    $table->string('follow_up_type', 20)->nullable()->after('follow_up_at')->index();
                }

                if (!Schema::hasColumn('follow_up_reminders', 'follow_up_start_date')) {
                    $table->date('follow_up_start_date')->nullable()->after('follow_up_date')->index();
                }

                if (!Schema::hasColumn('follow_up_reminders', 'follow_up_end_date')) {
                    $table->date('follow_up_end_date')->nullable()->after('follow_up_start_date')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('follow_up_reminders')) {
            Schema::table('follow_up_reminders', function (Blueprint $table) {
                foreach (['follow_up_end_date', 'follow_up_start_date', 'follow_up_type'] as $column) {
                    if (Schema::hasColumn('follow_up_reminders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('queue_tickets')) {
            Schema::table('queue_tickets', function (Blueprint $table) {
                foreach (['completed_at', 'consultation_id'] as $column) {
                    if (Schema::hasColumn('queue_tickets', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
