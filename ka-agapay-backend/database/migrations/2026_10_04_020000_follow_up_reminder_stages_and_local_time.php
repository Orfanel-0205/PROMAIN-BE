<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up reminders by SMS, and follow-up times in Philippine time.
 *
 * reminders_sent records which reminder texts have gone out, per stage
 * ({"three_days_before": {...}, "day_before": {...}}), so the daily run never
 * texts the same patient twice for the same stage.
 *
 * follow_up_at is corrected for every existing row. Staff enter a date and a
 * time meaning Philippine time; it was stored as if they meant UTC, so a
 * 9:00 AM follow-up was saved as 9:00 UTC and shown on the admin as 5:00 PM.
 * follow_up_date and follow_up_time always held what staff actually entered,
 * so follow_up_at is rebuilt from them. Running this twice gives the same
 * result.
 */
return new class extends Migration
{
    private const ZONE = 'Asia/Manila';

    public function up(): void
    {
        if (!Schema::hasColumn('follow_up_reminders', 'reminders_sent')) {
            Schema::table('follow_up_reminders', function (Blueprint $table) {
                $table->json('reminders_sent')->nullable();
            });
        }

        $this->rebuild(fn (string $date, string $time) => Carbon::parse("{$date} {$time}", self::ZONE)->utc());
    }

    public function down(): void
    {
        // Back to the old reading: the wall-clock time as UTC.
        $this->rebuild(fn (string $date, string $time) => Carbon::parse("{$date} {$time}", 'UTC'));

        if (Schema::hasColumn('follow_up_reminders', 'reminders_sent')) {
            Schema::table('follow_up_reminders', function (Blueprint $table) {
                $table->dropColumn('reminders_sent');
            });
        }
    }

    private function rebuild(callable $instant): void
    {
        DB::table('follow_up_reminders')
            ->whereNotNull('follow_up_date')
            ->orderBy('id')
            ->get(['id', 'follow_up_date', 'follow_up_time'])
            ->each(function ($row) use ($instant) {
                $date = substr((string) $row->follow_up_date, 0, 10);
                $time = $row->follow_up_time ? substr((string) $row->follow_up_time, 0, 5) : '09:00';

                DB::table('follow_up_reminders')
                    ->where('id', $row->id)
                    ->update(['follow_up_at' => $instant($date, $time)->format('Y-m-d H:i:s')]);
            });
    }
};
