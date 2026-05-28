<?php
// app/Http/Controllers/Api/BarangayController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BarangayController extends Controller
{
    public function index(): JsonResponse
    {
        $barangays = Cache::remember('barangays_list', now()->addHours(24), function () {
            return DB::table('barangays')
                ->orderBy('name')
                ->pluck('name')
                // FIX: trim() every name so no hidden whitespace ever
                // reaches the mobile app or the validator.
                ->map(fn (string $n) => trim($n))
                ->values();
        });

        return response()->json([
            'data'  => $barangays,
            'total' => $barangays->count(),
        ]);
    }
}