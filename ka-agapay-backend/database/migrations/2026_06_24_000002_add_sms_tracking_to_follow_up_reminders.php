<?php
// database/migrations/2026_06_24_000002_add_sms_tracking_to_follow_up_reminders.php
//
// Additive SMS tracking columns for follow_up_reminders. The reminder already
// has sms_status / sms_sent_at / sms_error / mobile_number; this adds:
//   - sms_log_id           : link to the latest sms_logs attempt
//   - sms_last_attempt_at   : when the last SMS attempt happened (sent or not)
//
// Guarded + idempotent; no columns are dropped or renamed.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('follow_up_reminders')) {
            return;
        }

        Schema::table('follow_up_reminders', function (Blueprint $table) {
            if (!Schema::hasColumn('follow_up_reminders', 'sms_log_id')) {
                $table->unsignedBigInteger('sms_log_id')->nullable();
            }

            if (!Schema::hasColumn('follow_up_reminders', 'sms_last_attempt_at')) {
                $table->timestamp('sms_last_attempt_at')->nullable();
            }

            // Safety net for older deployments where these may be missing.
            if (!Schema::hasColumn('follow_up_reminders', 'sms_status')) {
                $table->string('sms_status', 20)->nullable();
            }
            if (!Schema::hasColumn('follow_up_reminders', 'sms_sent_at')) {
                $table->timestamp('sms_sent_at')->nullable();
            }
            if (!Schema::hasColumn('follow_up_reminders', 'sms_error')) {
                $table->text('sms_error')->nullable();
            }
            if (!Schema::hasColumn('follow_up_reminders', 'mobile_number')) {
                $table->string('mobile_number', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('follow_up_reminders')) {
            return;
        }

        Schema::table('follow_up_reminders', function (Blueprint $table) {
            foreach (['sms_log_id', 'sms_last_attempt_at'] as $col) {
                if (Schema::hasColumn('follow_up_reminders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
