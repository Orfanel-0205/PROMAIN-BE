<?php
// app/Http/Controllers/Api/AdminSmsController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Sms\SemaphoreSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class AdminSmsController extends Controller
{
    public function __construct(
        private SemaphoreSmsService $semaphore
    ) {}

    public function account(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        try {
            return response()->json($this->semaphore->account());
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Could not load SMS provider account.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function logs(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = SmsLog::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('recipient_name', 'ilike', "%{$search}%")
                    ->orWhere('mobile_number', 'ilike', "%{$search}%")
                    ->orWhere('message', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $this->normalizeProviderStatus($request->status));
        }

        if ($request->filled('provider') && $request->provider !== 'all') {
            $query->where('provider', $request->provider);
        }

        $orderColumn = Schema::hasColumn('sms_logs', 'created_at') ? 'created_at' : 'id';

        $logs = $query
            ->orderByDesc($orderColumn)
            ->paginate($request->integer('per_page', 100));

        return response()->json($logs);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $validated = $this->validateSmsPayload($request);
        $recipients = $this->resolveRecipients($validated);

        return response()->json([
            'mode' => $validated['mode'],
            'count' => count($recipients),
            'estimated_credits' => $this->estimateCredits($validated['message'], count($recipients)),
            'recipients' => array_slice($recipients, 0, 100),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $validated = $this->validateSmsPayload($request);
        $recipients = $this->resolveRecipients($validated);

        if (count($recipients) === 0) {
            return response()->json([
                'message' => 'No valid SMS recipients found.',
            ], 422);
        }

        $logs = collect();
        $numbers = collect($recipients)->pluck('mobile_number')->values()->all();

        try {
            DB::beginTransaction();

            foreach ($recipients as $recipient) {
                $logs->push(SmsLog::create([
                    'user_id' => $recipient['user_id'] ?? null,
                    'sent_by' => $request->user()?->user_id ?? $request->user()?->id,
                    'recipient_name' => $recipient['recipient_name'] ?? 'Recipient',
                    'mobile_number' => $recipient['mobile_number'],
                    'message' => $validated['message'],
                    'mode' => $validated['mode'],
                    'target_filters' => $this->targetFilters($validated),
                    'notification_type' => $validated['notification_type'] ?? 'manual',
                    'provider' => $this->semaphore->providerName(),
                    'status' => 'queued',
                    'error_message' => null,
                    'sent_at' => null,
                ]));
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Could not create SMS logs.',
                'error' => $e->getMessage(),
            ], 422);
        }

        try {
            $providerResponses = $this->semaphore->sendBulk($numbers, $validated['message']);

            foreach ($logs->values() as $index => $log) {
                $providerResponse = $providerResponses[$index] ?? $providerResponses[0] ?? [];

                $log->update([
                    'provider' => $this->semaphore->providerName(),
                    'provider_message_id' => data_get($providerResponse, 'message_id')
                        ?? data_get($providerResponse, 'id')
                        ?? data_get($providerResponse, 'request_id'),
                    'status' => $this->normalizeProviderStatus(
                        data_get($providerResponse, 'status')
                            ?? data_get($providerResponse, 'success')
                            ?? data_get($providerResponse, 'raw_response.success')
                            ?? 'sent'
                    ),
                    'error_message' => null,
                    'sent_at' => now(),
                ]);
            }

            return response()->json([
                'message' => 'SMS request accepted by provider.',
                'provider' => $this->semaphore->providerName(),
                'count' => count($recipients),
                'estimated_credits' => $this->estimateCredits($validated['message'], count($recipients)),
                'provider_response' => $providerResponses,
            ]);
        } catch (Throwable $e) {
            foreach ($logs as $log) {
                $log->update([
                    'provider' => $this->semaphore->providerName(),
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'sent_at' => null,
                ]);
            }

            return response()->json([
                'message' => 'Failed to send SMS.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function validateSmsPayload(Request $request): array
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['nullable', 'string', 'max:150'],

            'mobile_number' => ['nullable', 'string', 'max:30'],
            'number' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'recipient' => ['nullable', 'string', 'max:30'],

            'message' => ['required', 'string', 'min:1', 'max:1000'],
            'notification_type' => ['nullable', 'string', 'max:100'],

            'role' => ['nullable', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'max:30'],
            'age_group' => ['nullable', 'string', 'max:50'],

            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'target_filters' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
        ]);

        $validated['mode'] = $this->normalizeMode($validated['mode'] ?? null);
        $validated['message'] = trim($validated['message']);

        return $validated;
    }

    private function resolveRecipients(array $validated): array
    {
        $mode = $validated['mode'];

        if ($mode === 'single') {
            $number = $validated['mobile_number']
                ?? $validated['number']
                ?? $validated['phone']
                ?? $validated['recipient']
                ?? null;

            $normalized = $this->semaphore->normalizePhoneNumber((string) $number);

            if (!$normalized) {
                throw new RuntimeException('Invalid mobile number. Use 09XXXXXXXXX, +639XXXXXXXXX, or 639XXXXXXXXX.');
            }

            return [[
                'user_id' => null,
                'recipient_name' => $validated['recipient_name'] ?? 'Manual Recipient',
                'mobile_number' => $normalized,
            ]];
        }

        $mobileColumn = $this->firstExistingColumn('users', [
            'mobile_number',
            'phone',
            'contact_number',
            'phone_number',
        ]);

        if (!$mobileColumn) {
            throw new RuntimeException('No mobile number column found in users table.');
        }

        $query = User::query()
            ->whereNotNull($mobileColumn)
            ->where($mobileColumn, '!=', '');

        if (Schema::hasColumn('users', 'account_status')) {
            $query->where(function ($q) {
                $q->whereNull('account_status')
                    ->orWhereNotIn('account_status', ['deleted', 'inactive', 'suspended']);
            });
        }

        $filters = array_merge(
            $validated['target_filters'] ?? [],
            $validated['filters'] ?? [],
            $validated
        );

        $role = $filters['role'] ?? null;

        if (in_array($mode, ['patient', 'patients'], true)) {
            $role = 'patient';
        }

        if ($role && Schema::hasColumn('users', 'role_id')) {
            $roleId = $this->resolveRoleId((string) $role);

            if ($roleId) {
                $query->where('role_id', $roleId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (!empty($filters['barangay']) && Schema::hasColumn('users', 'barangay')) {
            $query->where('barangay', $filters['barangay']);
        }

        if (!empty($filters['gender']) && Schema::hasColumn('users', 'gender')) {
            $query->where('gender', $filters['gender']);
        }

        $limit = (int) ($validated['limit'] ?? 1000);

        return $query
            ->limit(min(max($limit, 1), 1000))
            ->get()
            ->map(function ($user) use ($mobileColumn) {
                $normalized = $this->semaphore->normalizePhoneNumber((string) $user->{$mobileColumn});

                if (!$normalized) {
                    return null;
                }

                $primaryKey = Schema::hasColumn('users', 'user_id') ? 'user_id' : 'id';

                $name = trim(implode(' ', array_filter([
                    $user->first_name ?? null,
                    $user->last_name ?? null,
                ])));

                if ($name === '') {
                    $name = $user->name ?? 'Recipient';
                }

                return [
                    'user_id' => $user->{$primaryKey} ?? null,
                    'recipient_name' => $name,
                    'mobile_number' => $normalized,
                ];
            })
            ->filter()
            ->unique('mobile_number')
            ->values()
            ->all();
    }

    private function normalizeProviderStatus(mixed $status): string
    {
        if (is_array($status)) {
            $status = data_get($status, 'status')
                ?? data_get($status, 'success')
                ?? data_get($status, 'raw_response.status')
                ?? data_get($status, 'raw_response.success');
        }

        if ($status === true || $status === 1 || $status === '1') {
            return 'sent';
        }

        if ($status === false || $status === 0 || $status === '0') {
            return 'failed';
        }

        $value = strtolower(trim((string) $status));

        return match ($value) {
            'sent', 'success', 'successful', 'delivered', 'true' => 'sent',
            'queued', 'pending', 'processing' => 'queued',
            'failed', 'error', 'undelivered', 'false' => 'failed',
            default => $value ?: 'sent',
        };
    }

    private function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return match ($mode) {
            '', 'manual', 'single' => 'single',
            'all', 'bulk' => 'all',
            'patient', 'patients' => 'patients',
            'targeted', 'demographic', 'demographics', 'filter', 'filtered' => 'targeted',
            default => $mode,
        };
    }

    private function estimateCredits(string $message, int $recipientCount): int
    {
        $segments = max(1, (int) ceil(strlen($message) / 160));

        return $segments * max(1, $recipientCount);
    }

    private function targetFilters(array $validated): array
    {
        return [
            'mode' => $validated['mode'] ?? 'single',
            'provider' => $this->semaphore->providerName(),
            'role' => $validated['role'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'age_group' => $validated['age_group'] ?? null,
            'limit' => $validated['limit'] ?? 1000,
            'target_filters' => $validated['target_filters'] ?? null,
            'filters' => $validated['filters'] ?? null,
        ];
    }

    private function resolveRoleId(string $roleName): ?int
    {
        if (!Schema::hasTable('user_roles')) {
            return null;
        }

        $roleName = strtolower(str_replace([' ', '-'], '_', trim($roleName)));
        $roleKey = Schema::hasColumn('user_roles', 'role_id') ? 'role_id' : 'id';

        $columns = ['name', 'slug', 'role', 'title', 'code', 'role_name'];

        foreach ($columns as $column) {
            if (!Schema::hasColumn('user_roles', $column)) {
                continue;
            }

            $role = DB::table('user_roles')
                ->whereRaw("LOWER(REPLACE({$column}, ' ', '_')) = ?", [$roleName])
                ->first();

            if ($role) {
                return (int) $role->{$roleKey};
            }
        }

        return null;
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function authorizeSms(Request $request): void
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');
    }
}