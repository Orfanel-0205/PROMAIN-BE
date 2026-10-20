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
     * A calendar-date column (appointment_date, consultation_date, valid_until,
     * ...) rather than a UTC timestamp (created_at, issued_at, ...). Reports
     * pick their column at run time, so they ask.
     */
    public static function isDateColumn(string $column): bool
    {
        $name = str_contains($column, '.') ? substr((string) strrchr($column, '.'), 1) : $column;

        return $name === 'date' || $name === 'valid_until' || str_ends_with($name, '_date');
    }

    /**
     * Keep rows from one Philippine date to another, both included, whichever
     * kind of column it is. Either bound may be left out. Dates are "Y-m-d".
     *
     * Replaces whereBetween($column, [$from, $to]) with UTC-midnight bounds,
     * which put everything from midnight to 8:00 AM in Manila on the
     * previous day.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereInDays(EloquentBuilder|QueryBuilder $query, string $column, ?string $from, ?string $to): EloquentBuilder|QueryBuilder
    {
        if (self::isDateColumn($column)) {
            if ($from !== null && $from !== '') {
                $query->whereDate($column, '>=', Carbon::parse($from)->toDateString());
            }
            if ($to !== null && $to !== '') {
                $query->whereDate($column, '<=', Carbon::parse($to)->toDateString());
            }

            return $query;
        }

        if ($from !== null && $from !== '') {
            $query->where($column, '>=', self::dayRange($from)[0]);
        }
        if ($to !== null && $to !== '') {
            $query->where($column, '<', self::dayRange($to)[1]);
        }

        return $query;
    }

    /**
     * A value read straight from the database, on the Philippine clock. A
     * date ("2026-10-21") is that date; a timestamp is UTC and is converted,
     * so 01:00 UTC is 9:00 AM -- the hour a chart should show.
     */
    public static function fromStored(DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(self::zone());
        }

        $value = trim($value);

        return strlen($value) <= 10
            ? CarbonImmutable::parse($value, self::zone())
            : CarbonImmutable::parse($value, 'UTC')->setTimezone(self::zone());
    }

    /**
     * A stored timestamp as ISO 8601 UTC ("2026-10-20T23:30:00.000000Z"), the
     * way Eloquent sends dates. Raw query rows carry "2026-10-20 23:30:00"
     * with no zone, which a browser or phone reads as LOCAL time -- a 7:30 AM
     * visit shown as 11:30 PM the day before. Dates without a time, values
     * that already carry a zone, and nulls pass through unchanged.
     */
    public static function iso(DateTimeInterface|string|null $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->toJSON();
        }

        if ($value === null || trim($value) === '') {
            return $value;
        }

        $value = trim($value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/', $value)) {
            return $value; // a plain date, or already zoned
        }

        return CarbonImmutable::parse($value, 'UTC')->toJSON();
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
