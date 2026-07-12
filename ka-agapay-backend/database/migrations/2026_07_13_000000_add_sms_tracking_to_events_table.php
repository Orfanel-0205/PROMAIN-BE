<?php
// database/migrations/2026_07_13_000000_add_sms_tracking_to_events_table.php
//
// SMS-on-publish + 3-days-before reminder for CMS events/announcements.
// Both columns are idempotency guards so a post can never blast residents
// twice (e.g. publish → draft → publish again, or the scheduler re-running).
// Additive only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'sms_sent_at')) {
                $table->timestamp('sms_sent_at')->nullable();
            }

            if (!Schema::hasColumn('events', 'reminder_sms_sent_at')) {
                $table->timestamp('reminder_sms_sent_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'sms_sent_at')) {
                $table->dropColumn('sms_sent_at');
            }

            if (Schema::hasColumn('events', 'reminder_sms_sent_at')) {
                $table->dropColumn('reminder_sms_sent_at');
            }
        });
    }
};
