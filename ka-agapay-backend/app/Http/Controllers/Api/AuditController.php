<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditController extends Controller
{
    private array $allowedRoles = [
        'super_admin',
        'superadmin',
        'mho',
        'admin',
        'rhu_admin',
        'staff_admin',
        'it_staff',
    ];

    private array $deleteHistoryActions = [
        'announcement.deleted',
        'announcement.archived',
        'event.deleted',
        'event.archived',
        'appointment.cancelled',
        'appointment.rejected',
        'consultation.archived',
        'consultation.cancelled',
        'prescription.voided',
        'prescription.cancelled',
        'inventory.deleted',
        'inventory.archived',
        'user.disabled',
        'user.suspended',
        'user.deleted',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAudit($request);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:50'],
            'action' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'subject_type' => ['nullable', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'only_delete_history' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $query = ActivityLog::query()
            ->with('user:user_id,first_name,last_name,email,mobile_number')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $validated['user_id']))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $validated['module']))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $validated['action']))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $validated['severity']))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $validated['subject_type']))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $validated['subject_id']))
            ->when($request->boolean('only_delete_history'), fn ($q) => $q->whereIn('action', $this->deleteHistoryActions))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $validated['to'] . ' 23:59:59'))
            ->when($request->filled('search'), function ($q) use ($validated) {
                $search = trim((string) $validated['search']);

                $q->where(function ($inner) use ($search) {
                    $inner->where('action', 'ilike', "%{$search}%")
                        ->orWhere('module', 'ilike', "%{$search}%")
                        ->orWhere('subject_label', 'ilike', "%{$search}%")
                        ->orWhere('user_role', 'ilike', "%{$search}%");
                });
            })
            ->latest('created_at');

        return response()->json(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function deleteHistory(Request $request): JsonResponse
    {
        $request->merge(['only_delete_history' => true]);

        return $this->index($request);
    }

    public function subjectHistory(Request $request): JsonResponse
    {
        $this->authorizeAudit($request);

        $validated = $request->validate([
            'subject_type' => ['required', 'string'],
            'subject_id' => ['required', 'integer'],
        ]);

        $logs = ActivityLog::query()
            ->with('user:user_id,first_name,last_name,email,mobile_number')
            ->where('subject_type', $validated['subject_type'])
            ->where('subject_id', $validated['subject_id'])
            ->latest('created_at')
            ->get();

        return response()->json(['data' => $logs]);
    }

    public function userTimeline(Request $request, int $userId): JsonResponse
    {
        $this->authorizeAudit($request);

        $logs = ActivityLog::query()
            ->with('user:user_id,first_name,last_name,email,mobile_number')
            ->where('user_id', $userId)
            ->latest('created_at')
            ->paginate($request->integer('per_page', 30));

        return response()->json($logs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:50'],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'subject_type' => ['nullable', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer'],
            'subject_label' => ['nullable', 'string', 'max:255'],
            'old_values' => ['nullable', 'array'],
            'new_values' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        $log = ActivityLog::create([
            'user_id' => $user?->user_id ?? $user?->id,
            'user_role' => $user?->role?->name ?? $user?->role_name ?? 'unknown',
            'action' => $validated['action'],
            'module' => $validated['module'] ?? $validated['metadata']['module'] ?? 'admin',
            'severity' => $validated['severity'] ?? 'info',
            'subject_type' => $validated['subject_type'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
            'subject_label' => $validated['subject_label'] ?? null,
            'old_values' => $validated['old_values'] ?? [],
            'new_values' => $validated['new_values'] ?? [],
            'metadata' => $validated['metadata'] ?? [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_type' => $this->detectDeviceType($request->userAgent()),
            'http_method' => $request->method(),
            'route_name' => optional($request->route())->getName() ?? $request->path(),
        ]);

        return response()->json([
            'message' => 'Audit log recorded.',
            'data' => $log,
        ], 201);
    }

    private function authorizeAudit(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole($this->allowedRoles),
            403,
            'Access to audit logs is restricted.'
        );
    }

    private function detectDeviceType(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);

        if (str_contains($agent, 'mobile') || str_contains($agent, 'android') || str_contains($agent, 'iphone')) {
            return 'mobile';
        }

        if (str_contains($agent, 'tablet') || str_contains($agent, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }
}