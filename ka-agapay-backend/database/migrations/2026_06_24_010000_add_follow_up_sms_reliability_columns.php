<?php
// database/migrations/2026_06_24_010000_add_follow_up_sms_reliability_columns.php
//
// Additive follow-up SMS reliability fields. Keeps legacy columns intact.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('follow_up_reminders')) {
            Schema::table('follow_up_reminders', function (Blueprint $table) {
                if (!Schema::hasColumn('follow_up_reminders', 'sms_error_message')) {
                    $table->text('sms_error_message')->nullable();
                }
            });
        }

        if (Schema::hasTable('sms_logs')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('sms_logs', 'raw_response')) {
                    $table->json('raw_response')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('follow_up_reminders')) {
            Schema::table('follow_up_reminders', function (Blueprint $table) {
                if (Schema::hasColumn('follow_up_reminders', 'sms_error_message')) {
                    $table->dropColumn('sms_error_message');
                }
            });
        }

        if (Schema::hasTable('sms_logs')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                if (Schema::hasColumn('sms_logs', 'raw_response')) {
                    $table->dropColumn('raw_response');
                }
            });
        }
    }
};
