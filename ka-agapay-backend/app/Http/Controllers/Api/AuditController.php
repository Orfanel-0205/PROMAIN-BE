<?php
// app/Http/Controllers/Api/AuditController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Only super_admin and mho can view audit logs
        abort_unless(
            $request->user()->hasAnyRole(['super_admin', 'mho']),
            403,
            'Access to audit logs is restricted.'
        );

        $validated = $request->validate([
            'user_id'  => ['nullable', 'integer'],
            'module'   => ['nullable', 'string', 'max:50'],
            'action'   => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', 'in:info,warning,critical'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $logs = ActivityLog::with('user')
            ->when(fn() => $request->filled('user_id'),  fn($q) => $q->where('user_id', $request->user_id))
            ->when(fn() => $request->filled('module'),   fn($q) => $q->where('module', $request->module))
            ->when(fn() => $request->filled('action'),   fn($q) => $q->where('action', $request->action))
            ->when(fn() => $request->filled('severity'), fn($q) => $q->where('severity', $request->severity))
            ->when(fn() => $request->filled('from'),     fn($q) => $q->where('created_at', '>=', $request->from))
            ->when(fn() => $request->filled('to'),       fn($q) => $q->where('created_at', '<=', $request->to . ' 23:59:59'))
            ->latest('created_at')
            ->paginate($request->integer('per_page', 50));

        return response()->json($logs);
    }

    public function userTimeline(Request $request, int $userId): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['super_admin', 'mho']),
            403
        );

        $logs = ActivityLog::where('user_id', $userId)
            ->latest('created_at')
            ->paginate(30);

        return response()->json($logs);
    }

    public function subjectHistory(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['super_admin', 'mho']),
            403
        );

        $request->validate([
            'subject_type' => ['required', 'string'],
            'subject_id'   => ['required', 'integer'],
        ]);

        $logs = ActivityLog::where('subject_type', $request->subject_type)
            ->where('subject_id', $request->subject_id)
            ->with('user')
            ->latest('created_at')
            ->get();

        return response()->json(['data' => $logs]);
    }

 public function store(Request $request): JsonResponse
{
    // ── Accept both the mobile app's flat format AND the admin panel format ──
    // Mobile sends: { action, metadata, user_id, timestamp }
    // Admin panel sends: { action, module, severity, subject_type, metadata }

    $validated = $request->validate([
        'action'        => ['required', 'string', 'max:100'],
        // 'module' is optional — mobile app puts it inside metadata
        'module'        => ['nullable', 'string', 'max:50'],
        'severity'      => ['nullable', 'string', 'in:info,warning,critical'],
        'subject_type'  => ['nullable', 'string'],
        'subject_id'    => ['nullable', 'integer'],
        'subject_label' => ['nullable', 'string', 'max:255'],
        'metadata'      => ['nullable', 'array'],
        // Mobile-only fields — accepted but not stored directly
        'user_id'       => ['nullable', 'integer'],
        'timestamp'     => ['nullable', 'string'],
    ]);

    // Extract module: prefer top-level, fall back to metadata.module
    $module = $validated['module']
        ?? $validated['metadata']['module']
        ?? 'mobile';

    // Use authenticated user — ignore the user_id from the request body
    // (never trust client-supplied user identity)
    $authUser = $request->user();

    $log = ActivityLog::create([
        'user_id'       => $authUser->user_id,
        'user_role'     => $authUser->role?->name ?? 'resident',
        'action'        => $validated['action'],
        'module'        => $module,
        'severity'      => $validated['severity'] ?? 'info',
        'subject_type'  => $validated['subject_type'] ?? null,
        'subject_id'    => $validated['subject_id'] ?? null,
        'subject_label' => $validated['subject_label'] ?? null,
        'metadata'      => $validated['metadata'] ?? [],
        'ip_address'    => $request->ip(),
        'user_agent'    => $request->userAgent(),
        'http_method'   => $request->method(),
        'route_name'    => 'api.v1.logs.store',
        'created_at'    => now(),
    ]);

    return response()->json(['status' => 'success', 'data' => $log], 201);
}
}
