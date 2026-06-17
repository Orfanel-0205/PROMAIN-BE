<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AuditService
{
    public function log(
        Request $request,
        string $action,
        string $module,
        mixed $subject = null,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
        string $severity = 'info',
        ?string $subjectLabel = null
    ): ?ActivityLog {
        if (!Schema::hasTable('audit_logs')) {
            return null;
        }

        try {
            $user = $request->user();

            return ActivityLog::create([
                'user_id' => $user?->user_id ?? $user?->id,
                'user_role' => $this->resolveRoleName($user),
                'action' => $action,
                'module' => $module,
                'severity' => $severity,
                'subject_type' => $this->resolveSubjectType($subject),
                'subject_id' => $this->resolveSubjectId($subject),
                'subject_label' => $subjectLabel ?? $this->resolveSubjectLabel($subject),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'metadata' => $metadata,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_type' => $this->detectDeviceType($request->userAgent()),
                'http_method' => $request->method(),
                'route_name' => optional($request->route())->getName() ?? $request->path(),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Compatibility method for controllers calling $audit->info().
     */
    public function info(string $module, string $action, array $context = [], ?Request $request = null): void
    {
        $this->writeAuditCompat('INFO', $module, $action, $context, $request);
    }

    /**
     * Compatibility method for warning-level audit logs.
     */
    public function warning(string $module, string $action, array $context = [], ?Request $request = null): void
    {
        $this->writeAuditCompat('WARNING', $module, $action, $context, $request);
    }

    /**
     * Compatibility method for critical-level audit logs.
     */
    public function critical(string $module, string $action, array $context = [], ?Request $request = null): void
    {
        $this->writeAuditCompat('CRITICAL', $module, $action, $context, $request);
    }

    private function writeAuditCompat(
        string $severity,
        string $module,
        string $action,
        array $context = [],
        ?Request $request = null
    ): void {
        try {
            $request = $request ?: request();
            $user = $request->user();

            $actorId = $user?->user_id ?? $user?->id ?? null;

            $actorName =
                $user?->full_name
                ?? $user?->name
                ?? $user?->username
                ?? $user?->mobile_number
                ?? 'System';

            $payload = [
                'actor_id' => $actorId,
                'user_id' => $actorId,
                'actor_name' => $actorName,
                'module' => $module,
                'action' => $action,
                'record_type' => $context['record_type'] ?? $context['type'] ?? null,
                'record_id' => $context['record_id'] ?? $context['id'] ?? null,
                'record_label' => $context['record_label'] ?? $context['label'] ?? null,
                'reason' => $context['reason'] ?? $context['message'] ?? $action,
                'severity' => strtoupper($severity),
                'old_values' => $context['old_values'] ?? null,
                'new_values' => $context['new_values'] ?? $context,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (!Schema::hasTable('audit_logs')) {
                Log::info('AUDIT: ' . $action, $payload);
                return;
            }

            $columns = Schema::getColumnListing('audit_logs');
            $row = [];

            foreach ($payload as $key => $value) {
                if (!in_array($key, $columns, true)) {
                    continue;
                }

                if (is_array($value) || is_object($value)) {
                    $row[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                } else {
                    $row[$key] = $value;
                }
            }

            if (!empty($row)) {
                DB::table('audit_logs')->insert($row);
            }
        } catch (Throwable $e) {
            Log::warning('Audit logging failed: ' . $e->getMessage(), [
                'module' => $module,
                'action' => $action,
                'severity' => $severity,
                'context' => $context,
            ]);
        }
    }

    private function resolveRoleName(mixed $user): ?string
    {
        if (!$user) {
            return null;
        }

        if (isset($user->role) && is_string($user->role)) {
            return $user->role;
        }

        if (isset($user->role) && is_object($user->role)) {
            return $user->role->name
                ?? $user->role->role_name
                ?? $user->role->slug
                ?? null;
        }

        return $user->role_name ?? $user->account_type ?? null;
    }

    private function resolveSubjectType(mixed $subject): ?string
    {
        if (!$subject) {
            return null;
        }

        if ($subject instanceof Model) {
            return $subject::class;
        }

        if (is_string($subject)) {
            return $subject;
        }

        if (is_object($subject)) {
            return $subject::class;
        }

        return null;
    }

    private function resolveSubjectId(mixed $subject): ?int
    {
        if (!$subject) {
            return null;
        }

        if ($subject instanceof Model) {
            return (int) $subject->getKey();
        }

        if (is_object($subject) && isset($subject->id)) {
            return (int) $subject->id;
        }

        return null;
    }

    private function resolveSubjectLabel(mixed $subject): ?string
    {
        if (!$subject) {
            return null;
        }

        foreach (['title', 'name', 'full_name', 'prescription_number', 'item_code', 'email'] as $field) {
            if (is_object($subject) && !empty($subject->{$field})) {
                return (string) $subject->{$field};
            }

            if (is_array($subject) && !empty($subject[$field])) {
                return (string) $subject[$field];
            }
        }

        return null;
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