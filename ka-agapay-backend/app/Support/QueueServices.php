<?php
// app/Support/QueueServices.php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The services the RHU queues patients for.
 *
 * These were ten strings repeated across eight files: the validation rules,
 * the ticket-prefix map, two label maps, the controller's allow-list and the
 * admin's own copy. Adding a service meant editing all of them, and missing
 * one gave you a service that could be queued but not validated, or a ticket
 * with no prefix. They are rows in `rhu_services` now, and a super admin or
 * MHO maintains them from Administration → Health Services.
 *
 * Everything here is Schema-guarded and cached, and falls back to the original
 * ten if the table is missing — a fresh database mid-migration, or a test that
 * has not seeded it.
 */
final class QueueServices
{
    /**
     * The services as they were hardcoded before the table existed.
     *
     * Only a fallback. The ticket prefixes are printed on tickets patients
     * hold, so they are reproduced exactly.
     */
    private const FALLBACK = [
        ['code' => 'opd_consultation', 'name' => 'OPD Consultation', 'helper' => 'General check-up and common illness concerns', 'prefix' => 'OPD'],
        ['code' => 'prenatal_checkup', 'name' => 'Prenatal Checkup', 'helper' => 'Pregnant patients and maternal care', 'prefix' => 'PRE'],
        ['code' => 'immunization', 'name' => 'Immunization', 'helper' => 'Vaccination and child immunization', 'prefix' => 'IMM'],
        ['code' => 'family_planning', 'name' => 'Family Planning', 'helper' => 'Family planning consultation and services', 'prefix' => 'FP'],
        ['code' => 'tb_dots', 'name' => 'TB DOTS', 'helper' => 'Tuberculosis treatment and follow-up', 'prefix' => 'TB'],
        ['code' => 'laboratory', 'name' => 'Laboratory', 'helper' => 'Lab request and specimen processing', 'prefix' => 'LAB'],
        ['code' => 'dental', 'name' => 'Dental', 'helper' => 'Dental consultation and treatment', 'prefix' => 'DEN'],
        ['code' => 'emergency', 'name' => 'Emergency', 'helper' => 'Urgent cases that need immediate attention', 'prefix' => 'ER'],
        ['code' => 'medicine_release', 'name' => 'Medicine Release', 'helper' => 'Prescription claiming and medicine release', 'prefix' => 'MED'],
        ['code' => 'bhw_assisted', 'name' => 'BHW Assisted', 'helper' => 'Barangay Health Worker endorsed patients', 'prefix' => 'BHW'],
    ];

    /** The code used when nothing else is known. Never removable. */
    public const DEFAULT_CODE = 'opd_consultation';

    private const CACHE_KEY_ACTIVE = 'queue.services.active';
    private const CACHE_KEY_ALL = 'queue.services.all';
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Services staff may choose from, in display order.
     *
     * @return array<int, array{code:string,name:string,helper:string,prefix:string}>
     */
    public static function active(): array
    {
        return self::read(self::CACHE_KEY_ACTIVE, true);
    }

    /**
     * Every service, retired ones included.
     *
     * Used wherever a stored code has to be turned back into a name: a ticket
     * issued last month for a service since retired must still print its own
     * name, not a blank or a raw code.
     *
     * @return array<int, array{code:string,name:string,helper:string,prefix:string}>
     */
    public static function all(): array
    {
        return self::read(self::CACHE_KEY_ALL, false);
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_column(self::active(), 'code');
    }

    /**
     * Validation rule body for a service code, e.g. for `in:...`.
     *
     * Built from the active list, so a retired service stops being accepted on
     * new tickets while remaining readable on old ones.
     */
    public static function validationList(): string
    {
        return implode(',', self::codes());
    }

    /** The human name for a stored code, falling back to a readable form. */
    public static function label(string $code): string
    {
        foreach (self::all() as $service) {
            if ($service['code'] === $code) {
                return $service['name'];
            }
        }

        // A code from outside the catalogue entirely: better to print
        // "Some Service" than an empty cell on a ticket.
        return ucwords(str_replace('_', ' ', $code));
    }

    /** The ticket-number prefix for a stored code, e.g. OPD-014. */
    public static function prefix(string $code): string
    {
        foreach (self::all() as $service) {
            if ($service['code'] === $code) {
                return $service['prefix'];
            }
        }

        return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $code) ?: 'SVC', 0, 3));
    }

    /** Whether a code may be used on a NEW ticket. */
    public static function isActive(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /** Call after any write, or staff wait up to five minutes to see it. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY_ACTIVE);
        Cache::forget(self::CACHE_KEY_ALL);
    }

    /**
     * @return array<int, array{code:string,name:string,helper:string,prefix:string}>
     */
    private static function read(string $cacheKey, bool $activeOnly): array
    {
        try {
            return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($activeOnly) {
                if (!Schema::hasTable('rhu_services')) {
                    return self::FALLBACK;
                }

                $query = DB::table('rhu_services')
                    ->orderBy('sort_order')
                    ->orderBy('id');

                if ($activeOnly) {
                    $query->where('is_active', true);
                }

                $rows = $query->get(['code', 'name', 'helper', 'ticket_prefix']);

                if ($rows->isEmpty()) {
                    return self::FALLBACK;
                }

                return $rows
                    ->map(fn ($row) => [
                        'code' => (string) $row->code,
                        'name' => (string) $row->name,
                        'helper' => (string) ($row->helper ?? ''),
                        'prefix' => (string) $row->ticket_prefix,
                    ])
                    ->all();
            });
        } catch (Throwable) {
            // A queue that cannot read its service list must still issue
            // tickets. The fallback is the list this system ran on for months.
            return self::FALLBACK;
        }
    }
}
