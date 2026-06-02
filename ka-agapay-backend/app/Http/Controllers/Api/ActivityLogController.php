<?php
// app/Http/Controllers/Api/ActivityLogController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    // =========================================================================
    // STORE — POST /activity-logs
    // Called by the mobile app's logActivity() helper on every screen event.
    // =========================================================================

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action'   => ['required', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);

        $log = ActivityLog::create([
            'user_id'    => $request->user()?->user_id,
            'action'     => $data['action'],
            'metadata'   => $data['metadata'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['logged' => true, 'id' => $log->id], 201);
    }

    // =========================================================================
    // INDEX — GET /activity-logs   (admin only)
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        $logs = ActivityLog::with('user:user_id,first_name,last_name')
            ->when($request->filled('user_id'),
                fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('action'),
                fn ($q) => $q->where('action', $request->action))
            ->latest()
            ->paginate(50);

        return response()->json($logs);
    }
}