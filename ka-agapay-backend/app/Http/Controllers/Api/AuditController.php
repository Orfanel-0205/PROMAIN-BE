<?php
// app/Http/Controllers/Api/AuditController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuditController extends Controller
{
    private array $allowedRoles = [
        'super_admin',
        'superadmin',
        'mho',
        'municipal_mayor',
        'admin',
        'rhu_admin',
        'staff_admin',
        'it_staff',
    ];

    /*
     * These are exact action names that should appear in Delete History.
     * We also apply a pattern-based fallback below so simple actions like
     * "deleted" and "record.deleted" are not accidentally hidden.
     */
    private array $deleteHistoryActions = [
        'deleted',
        'archived',
        'cancelled',
        'rejected',
        'voided',
        'disabled',
        'suspended',

        'record.deleted',
        'record.archived',
        'record.expired',

        'announcement.deleted',
        'announcement.archived',

        'event.deleted',
        'event.archived',

        'appointment.cancelled',
        'appointment.rejected',
        'appointment.deleted',

        'consultation.archived',
        'consultation.cancelled',
        'consultation.deleted',

        'prescription.voided',
        'prescription.cancelled',
        'prescription.deleted',

        'inventory.deleted',
        'inventory.archived',

        'user.deleted',
        'user.disabled',
        'user.suspended',
        'user.deactivated',
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

        $like = $this->likeOperator();

        $query = AuditLog::query()
            ->with('user:user_id,first_name,last_name,email,mobile_number')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $validated['user_id']))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $validated['module']))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $validated['action']))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $validated['severity']))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $validated['subject_type']))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $validated['subject_id']))
            ->when($request->boolean('only_delete_history'), function ($q) {
                $this->applyDeleteHistoryFilter($q);
            })
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $validated['from']))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $validated['to'] . ' 23:59:59'))
            ->when($request->filled('search'), function ($q) use ($validated, $like) {
                $search = trim((string) $validated['search']);

                $q->where(function ($inner) use ($search, $like) {
                    $inner->where('action', $like, "%{$search}%")
                        ->orWhere('module', $like, "%{$search}%")
                        ->orWhere('subject_label', $like, "%{$search}%")
                        ->orWhere('user_role', $like, "%{$search}%")
                        ->orWhereRaw('metadata::text ' . $like . ' ?', ["%{$search}%"]);
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

        $logs = AuditLog::query()
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

        $logs = AuditLog::query()
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

        $log = AuditLog::create([
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

    private function applyDeleteHistoryFilter($query): void
    {
        $query->where(function ($q) {
            $q->whereIn('action', $this->deleteHistoryActions)
                ->orWhereRaw('LOWER(action) LIKE ?', ['%delete%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%deleted%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%archive%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%archived%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%cancel%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%cancelled%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%reject%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%rejected%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%void%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%voided%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%disable%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%disabled%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%suspend%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%suspended%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%expired%']);
        });
    }

    private function authorizeAudit(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole($this->allowedRoles),
            403,
            'Access to audit logs is restricted.'
        );
    }

    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
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