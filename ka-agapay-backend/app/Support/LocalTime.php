<?php
// app/Support/LocalTime.php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Philippine time, for everything people schedule and read.
 *
 * The application clock is UTC, which is right for storage and wrong for
 * people. Two bugs came from mixing them up:
 *
 *   A follow-up set for 9:00 AM was stored as 9:00 UTC and shown on the admin
 *   as 5:00 PM, because the browser then converted it to Philippine time.
 *
 *   Scheduled reminders ran on the UTC clock. The appointment reminder meant
 *   for "the evening before" went out at 1:00 AM on the day itself, still
 *   saying "tomorrow", because 1:00 AM in Manila is the previous day in UTC.
 *
 * So: times people enter are read as Philippine time and stored as UTC, and
 * "today" for anything a patient sees is the Philippine date.
 */
final class LocalTime
{
    public static function zone(): string
    {
        return (string) config('app.local_timezone', 'Asia/Manila');
    }

    /** Midnight today, in the Philippines. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone())->startOfDay();
    }

    /**
     * A date and wall-clock time entered in the Philippines, as the UTC
     * instant the database stores. "2026-10-07" + "09:00" -> 01:00 UTC.
     */
    public static function toUtc(string $date, ?string $time = null): Carbon
    {
        $clock = $time !== null && trim($time) !== '' ? substr(trim($time), 0, 5) : '09:00';

        return Carbon::parse("{$date} {$clock}", self::zone())->utc();
    }
}
