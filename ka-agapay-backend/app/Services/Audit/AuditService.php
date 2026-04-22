<?php
// app/Services/Audit/AuditService.php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Log;

class AuditService
{
    public function log(
        string $action,
        string $module,
        array  $options = []
    ): ?ActivityLog {
        try {
            $user = Auth::user();

            return ActivityLog::create([
                'user_id'       => $user?->user_id,
                'user_role'     => $user?->role?->name,
                'action'        => $action,
                'module'        => $module,
                'severity'      => $options['severity'] ?? 'info',
                'subject_type'  => isset($options['subject'])
                    ? get_class($options['subject'])
                    : ($options['subject_type'] ?? null),
                'subject_id'    => isset($options['subject'])
                    ? $options['subject']->getKey()
                    : ($options['subject_id'] ?? null),
                'subject_label' => $options['subject_label'] ?? null,
                'old_values'    => $options['old_values'] ?? null,
                'new_values'    => $options['new_values'] ?? null,
                'metadata'      => $options['metadata'] ?? null,
                'ip_address'    => Request::ip(),
                'user_agent'    => Request::userAgent(),
                'http_method'   => Request::method(),
                'route_name'    => Request::route()?->getName(),
            ]);
        } catch (\Throwable $e) {
            // Audit failure must NEVER crash the main request
            Log::error('AuditService::log failed', [
                'action'  => $action,
                'module'  => $module,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function info(string $action, string $module, array $options = []): ?ActivityLog
    {
        return $this->log($action, $module, array_merge($options, ['severity' => 'info']));
    }

    public function warning(string $action, string $module, array $options = []): ?ActivityLog
    {
        return $this->log($action, $module, array_merge($options, ['severity' => 'warning']));
    }

    public function critical(string $action, string $module, array $options = []): ?ActivityLog
    {
        return $this->log($action, $module, array_merge($options, ['severity' => 'critical']));
    }

    public function modelChange(
        Model  $model,
        string $action,
        array  $oldValues = [],
        array  $newValues = []
    ): ?ActivityLog {
        return $this->log($action, strtolower(class_basename($model)), [
            'subject'       => $model,
            'subject_label' => method_exists($model, 'getAuditLabel')
                ? $model->getAuditLabel()
                : class_basename($model) . ' #' . $model->getKey(),
            'old_values'    => $oldValues,
            'new_values'    => $newValues,
        ]);
    }
}
