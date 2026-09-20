<?php
// app/Support/Rhu.php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central RHU (facility) helper for Ka-Agapay.
 *
 * RHU IDs are FACILITY ids — NOT barangay ids. They are rows in the `rhus`
 * table: Malasiqui runs RHU 1 and RHU 2 today, and a super admin can open a
 * third without a developer (Administration → RHU Facilities).
 *
 * The barangay a resident belongs to determines their RHU via the
 * `barangays.rhu_id` mapping column. Appointments and queue tickets store the
 * facility rhu_id, never a barangay id.
 *
 * Everything here is Schema-guarded so it degrades safely if a table or column
 * has not been migrated yet (it falls back to the two original facilities).
 */
final class Rhu
{
    /**
     * Facilities come from the rhus table, so a municipality can open RHU 3
     * without a developer. These two are only the fallback for the moment
     * before that table exists: a fresh database mid-migration, or a test that
     * has not seeded it. See the 2026_09_20 create_rhus_table migration.
     */
    private const FALLBACK = [
        ['id' => 1, 'name' => 'RHU 1 Malasiqui', 'short' => 'RHU 1'],
        ['id' => 2, 'name' => 'RHU 2 Malasiqui (Don Pedro)', 'short' => 'RHU 2'],
    ];

    private const CACHE_KEY = 'rhu.facilities.active';
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Every active facility, cheapest-first: cached, because this is asked on
     * nearly every request that lists or scopes anything.
     *
     * @return array<int, array{id:int,name:string,short:string}>
     */
    public static function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
                if (!Schema::hasTable('rhus')) {
                    return self::FALLBACK;
                }

                $rows = DB::table('rhus')
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->get(['id', 'name', 'short_name']);

                if ($rows->isEmpty()) {
                    return self::FALLBACK;
                }

                return $rows
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'name' => (string) $row->name,
                        'short' => (string) ($row->short_name ?: $row->name),
                    ])
                    ->all();
            });
        } catch (\Throwable) {
            // A missing table or an unavailable cache store must not take the
            // whole API down: fall back to the facilities that always existed.
            return self::FALLBACK;
        }
    }

    /** Forget the cached list. Called whenever a facility is added or changed. */
    public static function flushCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Nothing cached.
        }
    }

    /**
     * Active facility ids.
     *
     * @return array<int, int>
     */
    public static function ids(): array
    {
        return array_map(static fn (array $rhu) => $rhu['id'], self::all());
    }

    /**
     * The facility that anything unassigned belongs to: the lowest active id,
     * which stays RHU 1 unless it is ever switched off.
     */
    public static function defaultId(): int
    {
        $ids = self::ids();

        return $ids === [] ? 1 : min($ids);
    }

    /** Return the id only if it is a real facility id, else null. */
    public static function normalizeRhuId(?int $rhuId): ?int
    {
        if ($rhuId === null) {
            return null;
        }

        return in_array((int) $rhuId, self::ids(), true) ? (int) $rhuId : null;
    }

    public static function rhuLabel(?int $rhuId): ?string
    {
        $id = self::normalizeRhuId($rhuId);

        if (!$id) {
            return null;
        }

        foreach (self::all() as $rhu) {
            if ($rhu['id'] === $id) {
                return $rhu['short'];
            }
        }

        return "RHU {$id}";
    }

    /**
     * Map a barangay id to the facility RHU that serves it (barangays.rhu_id).
     * Returns null when the mapping is unavailable/unknown.
     */
    public static function deriveRhuIdFromBarangayId(?int $barangayId): ?int
    {
        if (!$barangayId || $barangayId <= 0) {
            return null;
        }

        if (!Schema::hasTable('barangays') || !Schema::hasColumn('barangays', 'rhu_id')) {
            return null;
        }

        $rhu = DB::table('barangays')
            ->where('barangay_id', $barangayId)
            ->value('rhu_id');

        return self::normalizeRhuId($rhu !== null ? (int) $rhu : null);
    }

    public static function deriveRhuIdFromBarangayName(?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '' || !Schema::hasTable('barangays')) {
            return null;
        }

        $barangayId = (int) DB::table('barangays')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('barangay_id');

        return self::deriveRhuIdFromBarangayId($barangayId ?: null);
    }

    /**
     * Resolve the facility RHU id (1/2) for a user.
     *  - Staff: explicit assigned_rhu_id when it is already a facility id, else
     *    derived from their barangay.
     *  - Resident: derived from their resident_profile barangay (or user barangay).
     */
    public static function resolveRhuIdFromUser(?User $user): ?int
    {
        if (!$user) {
            return null;
        }

        $assigned = self::normalizeRhuId((int) ($user->assigned_rhu_id ?? 0) ?: null);

        if ($assigned) {
            return $assigned;
        }

        $userId = (int) ($user->user_id ?? $user->getKey() ?? 0);

        $barangayId = 0;

        if ($userId > 0 && Schema::hasTable('resident_profiles')) {
            $barangayId = (int) DB::table('resident_profiles')
                ->where('user_id', $userId)
                ->value('barangay_id');
        }

        if ($barangayId <= 0) {
            // assigned_rhu_id may legacy-hold a real barangay id; try it as one.
            $barangayId = (int) ($user->assigned_rhu_id ?? $user->barangay_id ?? 0);
        }

        $derived = self::deriveRhuIdFromBarangayId($barangayId ?: null);

        if ($derived) {
            return $derived;
        }

        return self::deriveRhuIdFromBarangayName($user->barangay ?? null);
    }

    public static function isGlobalScope(?User $user): bool
    {
        return $user?->isGlobalRhuScope() ?? false;
    }

    /**
     * Concrete RHU a request may operate on (always returns 1 or 2).
     *  - Global scope (super_admin/mho): requested rhu, else their own, else default.
     *  - Everyone else: HARD-LOCKED to their own RHU; any requested rhu is ignored.
     */
    public static function scopeRhuId(?User $user, ?int $requested): int
    {
        $requested = self::normalizeRhuId($requested);

        if (self::isGlobalScope($user)) {
            return $requested
                ?? self::resolveRhuIdFromUser($user)
                ?? self::defaultId();
        }

        return self::resolveRhuIdFromUser($user)
            ?? $requested
            ?? self::defaultId();
    }

    /**
     * Optional RHU a request may FILTER a list by (may return null = all RHUs).
     * Only global-scope users can see all RHUs; others are locked to their own.
     */
    public static function filterRhuId(?User $user, ?int $requested): ?int
    {
        $requested = self::normalizeRhuId($requested);

        if (self::isGlobalScope($user)) {
            return $requested; // null => all RHUs
        }

        return self::resolveRhuIdFromUser($user) ?? self::defaultId();
    }

    public static function canAccessRhu(?User $user, ?int $rhuId): bool
    {
        $rhuId = self::normalizeRhuId($rhuId);

        if (!$rhuId) {
            return false;
        }

        if (self::isGlobalScope($user)) {
            return true;
        }

        return self::resolveRhuIdFromUser($user) === $rhuId;
    }

    /**
     * Apply list scoping to an Eloquent query. Non-global users are locked to
     * their RHU; global users filter only when they requested a specific RHU.
     */
    public static function applyScope(Builder $query, ?User $user, ?int $requestedRhuId, string $column = 'rhu_id'): Builder
    {
        $effective = self::filterRhuId($user, $requestedRhuId);

        if ($effective !== null) {
            $query->where($column, $effective);
        }

        return $query;
    }
}
