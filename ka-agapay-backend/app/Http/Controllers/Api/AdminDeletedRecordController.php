<?php
// app/Http/Controllers/Api/AdminDeletedRecordController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Event;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AdminDeletedRecordController extends Controller
{
    private int $restoreWindowDays = 30;

    private array $restorableModels = [
        'announcements' => Announcement::class,
        'events' => Event::class,
        'appointments' => Appointment::class,
        'consultations' => Consultation::class,
        'inventory' => InventoryItem::class,
        'inventory_items' => InventoryItem::class,
        'users' => User::class,
    ];

    /*
     * Users are intentionally excluded from automatic permanent deletion.
     * Clinical records are also excluded for safety.
     */
    private array $expirableModels = [
        'announcements' => Announcement::class,
        'events' => Event::class,
        'inventory' => InventoryItem::class,
        'inventory_items' => InventoryItem::class,
    ];

    public function restore(Request $request, int $auditLogId): JsonResponse
    {
        $this->authorizeRestore($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $auditLog = AuditLog::findOrFail($auditLogId);

        abort_unless(
            $this->isDeleteAction((string) $auditLog->action),
            422,
            'This audit record is not a deleted record.'
        );

        abort_if(
            $auditLog->created_at &&
                $auditLog->created_at->lt(now()->subDays($this->restoreWindowDays)),
            422,
            "Restore window expired. Records can only be restored within {$this->restoreWindowDays} days."
        );

        $module = $this->normalizeModule((string) ($auditLog->module ?? ''));
        $modelClass = $this->restorableModels[$module] ?? null;

        abort_unless(
            $modelClass && class_exists($modelClass),
            422,
            'This module does not support automatic restore.'
        );

        abort_unless(
            $this->modelUsesSoftDeletes($modelClass),
            422,
            'This record type does not support soft-delete restore.'
        );

        $subjectId = $this->subjectId($auditLog);

        abort_unless(
            $subjectId,
            422,
            'The deleted record ID was not found in the audit log.'
        );

        /** @var Model|null $record */
        $record = $modelClass::withTrashed()->find($subjectId);

        abort_unless($record, 404, 'Deleted record was not found.');

        abort_unless(
            method_exists($record, 'trashed') && $record->trashed(),
            422,
            'This record is already active or was permanently removed.'
        );

        DB::transaction(function () use ($request, $record, $auditLog, $validated, $module) {
            $oldValues = $this->toArray($auditLog->old_values ?? null);

            $record->restore();

            $table = $record->getTable();

            $updates = [];

            if (Schema::hasColumn($table, 'deleted_by')) {
                $updates['deleted_by'] = null;
            }

            if (Schema::hasColumn($table, 'delete_reason')) {
                $updates['delete_reason'] = null;
            }

            // Inventory items are hidden on delete via is_active=false, so a plain
            // restore() would leave them invisible in the active list. Return
            // is_active to its exact pre-delete value from the snapshot.
            if (in_array($module, ['inventory', 'inventory_items'], true) && Schema::hasColumn($table, 'is_active')) {
                $priorActive = $oldValues['is_active'] ?? true;
                $updates['is_active'] = filter_var($priorActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            }

            if ($module === 'users') {
                if (Schema::hasColumn($table, 'account_status')) {
                    $oldStatus = $this->normalizeStatus(
                        (string) ($oldValues['account_status'] ?? '')
                    );

                    $updates['account_status'] = in_array($oldStatus, [
                        'pending',
                        'active',
                        'inactive',
                        'suspended',
                        'rejected',
                    ], true)
                        ? $oldStatus
                        : 'active';
                }

                if (Schema::hasColumn($table, 'rejection_reason')) {
                    $updates['rejection_reason'] = null;
                }
            }

            if (!empty($updates)) {
                $record->forceFill($updates)->save();
            }

            $this->writeAuditLog($request, [
                'module' => $module,
                'action' => 'record.restored',

                'subject_type' => get_class($record),
                'subject_id' => $record->getKey(),
                'subject_label' => $this->recordLabel($record),

                'record_type' => get_class($record),
                'record_id' => $record->getKey(),
                'record_name' => $this->recordLabel($record),

                'severity' => 'warning',
                'metadata' => [
                    'reason' => $validated['reason'] ?? 'Record restored from Delete & Archive History.',
                    'source_audit_log_id' => $auditLog->id,
                    'restore_window_days' => $this->restoreWindowDays,
                    'restored_module' => $module,
                    'restored_id' => $record->getKey(),
                ],
            ]);
        });

        return response()->json([
            'message' => 'Record restored successfully.',
            'restored' => true,
        ]);
    }

    public function expire(Request $request): JsonResponse
    {
        $this->authorizeRestore($request);

        $validated = $request->validate([
            'retention_days' => ['required', 'integer', 'min:30', 'max:3650'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $retentionDays = (int) $validated['retention_days'];
        $batchSize = min((int) ($validated['batch_size'] ?? 50), 50);
        $dryRun = (bool) ($validated['dry_run'] ?? false);

        $cutoff = now()->subDays($retentionDays);
        $expiredCount = 0;
        $remaining = $batchSize;

        DB::transaction(function () use ($request, $cutoff, $batchSize, $dryRun, &$expiredCount, &$remaining) {
            foreach ($this->expirableModels as $module => $modelClass) {
                if ($remaining <= 0) {
                    break;
                }

                if (!class_exists($modelClass) || !$this->modelUsesSoftDeletes($modelClass)) {
                    continue;
                }

                /** @var Model $model */
                $model = new $modelClass();

                if (!Schema::hasTable($model->getTable())) {
                    continue;
                }

                $records = $modelClass::onlyTrashed()
                    ->where('deleted_at', '<=', $cutoff)
                    ->limit($remaining)
                    ->get();

                foreach ($records as $record) {
                    if ($dryRun) {
                        $expiredCount++;
                        $remaining--;
                        continue;
                    }

                    $this->deleteKnownStorageFiles($record);

                    $recordId = $record->getKey();
                    $recordLabel = $this->recordLabel($record);
                    $recordClass = get_class($record);

                    $record->forceDelete();

                    $this->writeAuditLog($request, [
                        'module' => $module,
                        'action' => 'record.expired',

                        'subject_type' => $recordClass,
                        'subject_id' => $recordId,
                        'subject_label' => $recordLabel,

                        'record_type' => $recordClass,
                        'record_id' => $recordId,
                        'record_name' => $recordLabel,

                        'severity' => 'warning',
                        'metadata' => [
                            'reason' => 'Expired old soft-deleted record after retention window.',
                            'retention_cutoff' => $cutoff->toISOString(),
                            'batch_size' => $batchSize,
                        ],
                    ]);

                    $expiredCount++;
                    $remaining--;
                }
            }
        });

        return response()->json([
            'message' => $dryRun
                ? 'Expiration dry run completed.'
                : 'Old deleted records expired successfully.',
            'expired_count' => $expiredCount,
            'dry_run' => $dryRun,
            'retention_days' => $retentionDays,
            'batch_size' => $batchSize,
            'cutoff' => $cutoff->toISOString(),
            'skipped_modules' => [
                'appointments',
                'consultations',
                'prescriptions',
                'users',
                'audit_logs',
            ],
        ]);
    }

    private function authorizeRestore(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole([
                'admin',
                'staff_admin',
                'rhu_admin',
                'mho',
                'municipal_mayor',
                'it_staff',
                'super_admin',
                'superadmin',
            ]),
            403,
            'Only authorized RHU admins can restore or expire deleted records.'
        );
    }

    private function isDeleteAction(string $action): bool
    {
        $action = strtolower($action);

        return str_contains($action, 'delete') ||
            str_contains($action, 'deleted') ||
            str_contains($action, 'soft_deleted');
    }

    private function normalizeModule(string $module): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($module)));
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($status)));
    }

    private function subjectId(AuditLog $auditLog): ?int
    {
        foreach (['subject_id', 'record_id'] as $directColumn) {
            if (isset($auditLog->{$directColumn}) && $auditLog->{$directColumn}) {
                return (int) $auditLog->{$directColumn};
            }
        }

        $metadata = $this->toArray($auditLog->metadata ?? null);

        foreach ([
            'restore_id',
            'subject_id',
            'record_id',
            'user_id',
            'id',
        ] as $key) {
            if (!empty($metadata[$key])) {
                return (int) $metadata[$key];
            }
        }

        $oldValues = $this->toArray($auditLog->old_values ?? null);

        foreach ([
            'user_id',
            'id',
            'record_id',
            'subject_id',
        ] as $key) {
            if (!empty($oldValues[$key])) {
                return (int) $oldValues[$key];
            }
        }

        return null;
    }

    private function toArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function modelUsesSoftDeletes(string $modelClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);
    }

    private function recordLabel(Model $record): string
    {
        if ($record instanceof User) {
            $name = trim((string) $record->first_name . ' ' . (string) $record->last_name);

            if ($name !== '') {
                return $name;
            }

            return 'User #' . $record->getKey();
        }

        foreach ([
            'title',
            'name',
            'full_name',
            'event_title',
            'subject',
            'prescription_number',
            'queue_number',
            'item_name',
        ] as $field) {
            if (!empty($record->{$field})) {
                return (string) $record->{$field};
            }
        }

        if (method_exists($record, 'getFullNameAttribute')) {
            return (string) $record->full_name;
        }

        return class_basename($record) . ' #' . $record->getKey();
    }

    private function deleteKnownStorageFiles(Model $record): void
    {
        $table = $record->getTable();

        foreach ([
            'image',
            'image_path',
            'banner_image',
            'file_path',
            'attachment_path',
            'photo',
            'photo_path',
        ] as $column) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }

            $path = $record->{$column};

            if (!$path || !is_string($path)) {
                continue;
            }

            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                continue;
            }

            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable) {
                // Do not fail expiration because of a missing file.
            }
        }
    }

    private function writeAuditLog(Request $request, array $data): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $columns = Schema::getColumnListing('audit_logs');
        $payload = [];

        $map = [
            'user_id' => $request->user()?->user_id,
            'actor_id' => $request->user()?->user_id,
            'actor_name' => $request->user()?->full_name,
            'user_role' => $request->user()?->role_name,

            'module' => $data['module'] ?? null,
            'action' => $data['action'] ?? null,

            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'subject_label' => $data['subject_label'] ?? null,

            'record_type' => $data['record_type'] ?? $data['subject_type'] ?? null,
            'record_id' => $data['record_id'] ?? $data['subject_id'] ?? null,
            'record_name' => $data['record_name'] ?? $data['subject_label'] ?? null,

            'severity' => $data['severity'] ?? 'info',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),

            'metadata' => json_encode($data['metadata'] ?? []),
            'old_values' => json_encode($data['old_values'] ?? []),
            'new_values' => json_encode($data['new_values'] ?? []),

            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach ($map as $column => $value) {
            if (in_array($column, $columns, true)) {
                $payload[$column] = $value;
            }
        }

        if (!empty($payload)) {
            DB::table('audit_logs')->insert($payload);
        }
    }
}