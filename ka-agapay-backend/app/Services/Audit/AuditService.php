<?php
// app/Services/Audit/AuditService.php

namespace App\Services\Audit;

use App\Models\AuditLog;
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
    ): ?AuditLog {
        if (!Schema::hasTable('audit_logs')) {
            return null;
        }

        try {
            $user = $request->user();

            return AuditLog::create([
                'user_id' => $user?->user_id ?? $user?->id,
                'user_role' => $this->resolveRoleName($user),
                'action' => $action,
                'module' => $module,
                'severity' => strtolower($severity),
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
        } catch (Throwable $e) {
            Log::warning('Audit logging failed: ' . $e->getMessage(), [
                'module' => $module,
                'action' => $action,
                'severity' => $severity,
            ]);

            return null;
        }
    }

    public function info(string $module, string $action, array $context = [], ?Request $request = null): void
    {
        $this->writeAuditCompat('info', $module, $action, $context, $request);
    }

    public function warning(string $module, string $action, array $context = [], ?Request $request = null): void
    {
        $this->writeAuditCompat('warning', $module, $action, $context, $request);
    }

    public function critical(string $module, string $action, array $context = [], ?Request $request = null): void
    {
        $this->writeAuditCompat('critical', $module, $action, $context, $request);
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

            $subjectType = $context['subject_type']
                ?? $context['record_type']
                ?? $context['type']
                ?? null;

            $subjectId = $context['subject_id']
                ?? $context['record_id']
                ?? $context['id']
                ?? null;

            $subjectLabel = $context['subject_label']
                ?? $context['record_label']
                ?? $context['record_name']
                ?? $context['label']
                ?? null;

            $metadata = $context['metadata'] ?? $context;

            if (!is_array($metadata)) {
                $metadata = [];
            }

            $metadata['module'] = $metadata['module'] ?? $module;

            if ($subjectId) {
                $metadata['subject_id'] = $metadata['subject_id'] ?? $subjectId;
                $metadata['record_id'] = $metadata['record_id'] ?? $subjectId;
                $metadata['restore_id'] = $metadata['restore_id'] ?? $subjectId;
            }

            if ($subjectType) {
                $metadata['subject_type'] = $metadata['subject_type'] ?? $subjectType;
                $metadata['record_type'] = $metadata['record_type'] ?? $subjectType;
            }

            if ($subjectLabel) {
                $metadata['subject_label'] = $metadata['subject_label'] ?? $subjectLabel;
                $metadata['record_name'] = $metadata['record_name'] ?? $subjectLabel;
            }

            $payload = [
                'user_id' => $actorId,
                'user_role' => $this->resolveRoleName($user),
                'module' => $module,
                'action' => $action,
                'severity' => strtolower($severity),

                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'subject_label' => $subjectLabel,

                'old_values' => $context['old_values'] ?? null,
                'new_values' => $context['new_values'] ?? null,
                'metadata' => $metadata,

                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_type' => $this->detectDeviceType($request->userAgent()),
                'http_method' => $request->method(),
                'route_name' => optional($request->route())->getName() ?? $request->path(),

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

        if (is_array($subject) && isset($subject['id'])) {
            return (int) $subject['id'];
        }

        return null;
    }

    private function resolveSubjectLabel(mixed $subject): ?string
    {
        if (!$subject) {
            return null;
        }

        foreach ([
            'title',
            'name',
            'full_name',
            'first_name',
            'prescription_number',
            'item_code',
            'item_name',
            'email',
        ] as $field) {
            if (is_object($subject) && !empty($subject->{$field})) {
                if ($field === 'first_name') {
                    return trim((string) $subject->first_name . ' ' . (string) ($subject->last_name ?? ''));
                }

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