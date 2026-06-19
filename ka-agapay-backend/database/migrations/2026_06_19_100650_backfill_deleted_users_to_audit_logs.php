<?php
// database/migrations/2026_06_19_100650_backfill_deleted_users_to_audit_logs.php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('audit_logs')) {
            return;
        }

        $columns = Schema::getColumnListing('audit_logs');

        User::onlyTrashed()
            ->orderBy('user_id')
            ->chunkById(100, function ($users) use ($columns) {
                foreach ($users as $user) {
                    $alreadyLoggedQuery = DB::table('audit_logs')
                        ->where('module', 'users')
                        ->where(function ($query) use ($user, $columns) {
                            $hasCondition = false;

                            if (in_array('subject_id', $columns, true)) {
                                $query->where('subject_id', $user->user_id);
                                $hasCondition = true;
                            }

                            if (in_array('metadata', $columns, true)) {
                                $patterns = [
                                    '%"restore_id":' . $user->user_id . '%',
                                    '%"restore_id": ' . $user->user_id . '%',
                                    '%"subject_id":' . $user->user_id . '%',
                                    '%"subject_id": ' . $user->user_id . '%',
                                    '%"record_id":' . $user->user_id . '%',
                                    '%"record_id": ' . $user->user_id . '%',
                                ];

                                foreach ($patterns as $pattern) {
                                    if ($hasCondition) {
                                        $query->orWhereRaw('metadata::text LIKE ?', [$pattern]);
                                    } else {
                                        $query->whereRaw('metadata::text LIKE ?', [$pattern]);
                                        $hasCondition = true;
                                    }
                                }
                            }

                            if (!$hasCondition) {
                                $query->whereRaw('1 = 0');
                            }
                        });

                    if ($alreadyLoggedQuery->exists()) {
                        continue;
                    }

                    $recordName = trim((string) $user->first_name . ' ' . (string) $user->last_name);

                    if ($recordName === '') {
                        $recordName = 'User #' . $user->user_id;
                    }

                    $reason = $user->delete_reason ?: 'Backfilled deleted user audit record.';

                    $oldValues = $user->toArray();

                    $metadata = [
                        'reason' => $reason,
                        'delete_reason' => $reason,
                        'module' => 'users',

                        'subject_type' => User::class,
                        'subject_id' => $user->user_id,
                        'subject_label' => $recordName,

                        'record_type' => User::class,
                        'record_id' => $user->user_id,
                        'record_name' => $recordName,

                        'restore_model' => User::class,
                        'restore_key' => 'user_id',
                        'restore_id' => $user->user_id,

                        'backfilled' => true,
                    ];

                    $payload = [
                        'user_id' => $user->deleted_by,
                        'user_role' => null,

                        'module' => 'users',
                        'action' => 'user.deleted',
                        'severity' => 'warning',

                        'subject_type' => User::class,
                        'subject_id' => $user->user_id,
                        'subject_label' => $recordName,

                        'old_values' => json_encode($oldValues),
                        'new_values' => null,
                        'metadata' => json_encode($metadata),

                        'ip_address' => '127.0.0.1',
                        'user_agent' => 'Backfill deleted users migration',
                        'device_type' => 'server',
                        'http_method' => 'MIGRATION',
                        'route_name' => 'backfill.deleted.users',

                        'created_at' => $user->deleted_at ?? now(),
                        'updated_at' => now(),
                    ];

                    $safePayload = collect($payload)
                        ->filter(fn ($value, $key) => in_array($key, $columns, true))
                        ->toArray();

                    if (!empty($safePayload)) {
                        DB::table('audit_logs')->insert($safePayload);
                    }
                }
            }, 'user_id');
    }

    public function down(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $columns = Schema::getColumnListing('audit_logs');

        if (!in_array('module', $columns, true) || !in_array('metadata', $columns, true)) {
            return;
        }

        DB::table('audit_logs')
            ->where('module', 'users')
            ->whereRaw('metadata::text LIKE ?', ['%"backfilled":true%'])
            ->delete();
    }
};