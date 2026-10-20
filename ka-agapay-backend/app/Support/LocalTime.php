<?php
// app/Support/LocalTime.php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 *
 * A third came from Laravel's today(), which is the UTC date. From midnight
 * to 8:00 AM in Manila that is still yesterday (Oct 2026): a resident's
 * booking for the day vanished from the Active board, and a ticket taken at
 * 7:30 AM went on the previous day's counter and dropped out of "today's
 * queue" at 8:00. Never use today() or now()->toDateString() for a day
 * people mean; use todayDate() for date columns and whereOnDay() for
 * timestamp columns.
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
     * Today's Philippine date, "Y-m-d" — what a date column (appointment_date,
     * queue_date, follow_up_date, ...) holds for today.
     */
    public static function todayDate(): string
    {
        return self::today()->toDateString();
    }

    /** Now, on the Philippine clock -- for any date or time a person reads. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /** The Philippine wall-clock time now, "H:i". */
    public static function nowTime(): string
    {
        return CarbonImmutable::now(self::zone())->format('H:i');
    }

    /**
     * The Philippine date of a stored instant (timestamp columns hold UTC).
     * A plain "Y-m-d" string is already a date and is returned as it is.
     */
    public static function dateOf(DateTimeInterface|string $value): string
    {
        if (is_string($value)) {
            return Carbon::parse($value)->toDateString();
        }

        return CarbonImmutable::instance($value)->setTimezone(self::zone())->toDateString();
    }

    /**
     * A Philippine day as the UTC instants timestamp columns are stored in:
     * [start, end). Today when no date is given.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayRange(DateTimeInterface|string|null $date = null): array
    {
        $start = CarbonImmutable::parse($date === null ? self::todayDate() : self::dateOf($date), self::zone())
            ->startOfDay();

        return [Carbon::instance($start->utc()), Carbon::instance($start->addDay()->utc())];
    }

    /** Start of a Philippine day, as the UTC instant the database stores. */
    public static function dayStartUtc(DateTimeInterface|string|null $date = null): Carbon
    {
        return self::dayRange($date)[0];
    }

    /**
     * Keep rows whose timestamp column falls on a Philippine day (today by
     * default). For timestamp columns only; a date column compares with
     * todayDate() directly.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereOnDay(EloquentBuilder|QueryBuilder $query, string $column, DateTimeInterface|string|null $date = null): EloquentBuilder|QueryBuilder
    {
        [$start, $end] = self::dayRange($date);

        return $query->where($column, '>=', $start)->where($column, '<', $end);
    }

    /**
     * Keep rows whose timestamp column falls between two Philippine days,
     * both included.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereBetweenDays(EloquentBuilder|QueryBuilder $query, string $column, DateTimeInterface|string $from, DateTimeInterface|string $to): EloquentBuilder|QueryBuilder
    {
        return $query
            ->where($column, '>=', self::dayRange($from)[0])
            ->where($column, '<', self::dayRange($to)[1]);
    }

    /**
     * SQL for the Philippine date of a timestamp column, for grouping by day.
     * The column holds UTC without a zone, so it is read as UTC first.
     */
    public static function sqlDate(string $column): string
    {
        $zone = self::zone();

        if (! preg_match('/^[A-Za-z_]+(\/[A-Za-z_+\-]+)*$/', $zone) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) {
            throw new \InvalidArgumentException('Unexpected timezone or column name.');
        }

        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql'
            ? "DATE(({$column} AT TIME ZONE 'UTC') AT TIME ZONE '{$zone}')"
            : "DATE({$column})";
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
