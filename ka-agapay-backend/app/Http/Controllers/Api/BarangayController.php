<?php
// app/Http/Controllers/Api/BarangayController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BarangayController extends Controller
{
    /**
     * Canonical Malasiqui barangay list.
     *
     * `data` is a flat array of NAMES and must stay that way: the mobile
     * registration screen and the web admin's auth/SMS helpers all read it as
     * strings.
     *
     * `options` is additive and carries the real primary key. It exists because
     * a name-only list cannot satisfy `exists:barangays,barangay_id`: the web
     * admin's option normalizer fell back to id 0 for every entry, so the
     * walk-in patient form posted barangay_id=0 and every barangay was rejected
     * with "The selected barangay id is invalid". Note the key is barangay_id,
     * not id.
     */
    public function index(): JsonResponse
    {
        // Cache key is versioned: the previous key holds a names-only payload
        // with a 24h TTL, and reusing it would keep serving idless data.
        $barangays = Cache::remember('barangays_list_v2', now()->addHours(24), function () {
            return DB::table('barangays')
                ->orderBy('name')
                ->get(['barangay_id', 'name', 'rhu_id'])
                // FIX: trim() every name so no hidden whitespace ever
                // reaches the mobile app or the validator.
                ->map(fn ($row) => [
                    'barangay_id' => (int) $row->barangay_id,
                    'name' => trim((string) $row->name),
                    'rhu_id' => $row->rhu_id !== null ? (int) $row->rhu_id : null,
                ])
                ->values();
        });

        return response()->json([
            // Unchanged shape for existing string consumers.
            'data'  => $barangays->pluck('name')->values(),
            // New: id-bearing rows for anything that must post a barangay_id.
            'options' => $barangays,
            'total' => $barangays->count(),
        ]);
    }
}