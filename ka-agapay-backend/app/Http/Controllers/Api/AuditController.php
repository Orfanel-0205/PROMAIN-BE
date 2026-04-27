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
}
