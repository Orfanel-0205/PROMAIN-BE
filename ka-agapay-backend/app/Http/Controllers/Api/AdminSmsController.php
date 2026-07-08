<?php
// app/Http/Controllers/Api/AdminSmsController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Sms\SemaphoreSmsService;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            $account = $this->semaphore->account();

            return response()->json(array_merge([
                'provider' => $this->semaphore->providerName(),
                'status' => 'connected',
            ], $account));
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

        $operator = $this->textOperator();

        $query = SmsLog::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search, $operator) {
                if (Schema::hasColumn('sms_logs', 'recipient_name')) {
                    $q->orWhere('recipient_name', $operator, "%{$search}%");
                }

                if (Schema::hasColumn('sms_logs', 'mobile_number')) {
                    $q->orWhere('mobile_number', $operator, "%{$search}%");
                }

                if (Schema::hasColumn('sms_logs', 'message')) {
                    $q->orWhere('message', $operator, "%{$search}%");
                }

                if (Schema::hasColumn('sms_logs', 'notification_type')) {
                    $q->orWhere('notification_type', $operator, "%{$search}%");
                }
            });
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $this->normalizeProviderStatus($request->status));
        }

        if ($request->filled('provider') && $request->provider !== 'all') {
            $query->where('provider', $request->provider);
        }

        $orderColumn = Schema::hasColumn('sms_logs', 'created_at')
            ? 'created_at'
            : 'id';

        $logs = $query
            ->orderByDesc($orderColumn)
            ->paginate($request->integer('per_page', 100));

        return response()->json($logs);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        try {
            $validated = $this->validateSmsPayload($request, true);
            $recipients = $this->resolveRecipients($validated, $request->user());

            return response()->json([
                'mode' => $validated['mode'],
                'count' => count($recipients),
                'estimated_credits' => $this->estimateCredits(
                    $validated['message'],
                    count($recipients)
                ),
                'recipients' => array_slice($recipients, 0, 100),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Could not preview SMS recipients.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        try {
            $validated = $this->validateSmsPayload($request);
            $recipients = $this->resolveRecipients($validated, $request->user());
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Invalid SMS request.',
                'error' => $e->getMessage(),
            ], 422);
        }

        if (count($recipients) === 0) {
            return response()->json([
                'message' => 'No valid SMS recipients found.',
            ], 422);
        }

        $logs = collect();
        $numbers = collect($recipients)
            ->pluck('mobile_number')
            ->filter()
            ->unique()
            ->values()
            ->all();

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
                    'provider_message_id' => null,
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
            $providerResponses = $this->semaphore->sendBulk(
                $numbers,
                $validated['message']
            );

            foreach ($logs->values() as $index => $log) {
                $providerResponse = $providerResponses[$index]
                    ?? $providerResponses[0]
                    ?? [];

                $log->update([
                    'provider' => $this->semaphore->providerName(),
                    'provider_message_id' => data_get($providerResponse, 'message_id')
                        ?? data_get($providerResponse, 'id')
                        ?? data_get($providerResponse, 'request_id'),
                    'status' => $this->normalizeProviderStatus(
                        data_get($providerResponse, 'status')
                            ?? data_get($providerResponse, 'success')
                            ?? data_get($providerResponse, 'raw_response.success')
                            ?? 'queued'
                    ),
                    'error_message' => null,
                    'sent_at' => now(),
                ]);
            }

            return response()->json([
                'message' => 'SMS request accepted by provider.',
                'provider' => $this->semaphore->providerName(),
                'count' => count($recipients),
                'estimated_credits' => $this->estimateCredits(
                    $validated['message'],
                    count($recipients)
                ),
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

    private function validateSmsPayload(Request $request, bool $previewOnly = false): array
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['nullable', 'string', 'max:150'],

            'mobile_number' => ['nullable', 'string', 'max:30'],
            'number' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'recipient' => ['nullable', 'string', 'max:30'],

            'message' => $previewOnly
                ? ['nullable', 'string', 'max:1000']
                : ['required', 'string', 'min:1', 'max:1000'],
            'notification_type' => ['nullable', 'string', 'max:100'],

            'role' => ['nullable', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'max:30'],
            'sex' => ['nullable', 'string', 'max:30'],
            'age_group' => ['nullable', 'string', 'max:50'],

            'age_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'age_max' => ['nullable', 'integer', 'min:0', 'max:120'],

            'account_status' => ['nullable', 'string', 'max:50'],
            'id_verified' => ['nullable', 'boolean'],
            'rhu_id' => ['nullable', 'integer'],

            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'target_filters' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
        ]);

        $validated['mode'] = $this->normalizeMode($validated['mode'] ?? null);
        $validated['message'] = trim((string) ($validated['message'] ?? ''));

        if (!$previewOnly && $validated['message'] === '') {
            throw new RuntimeException('SMS message is empty.');
        }

        if ($validated['message'] !== '' && str_starts_with(strtoupper($validated['message']), 'TEST')) {
            throw new RuntimeException('Do not start SMS messages with TEST. Semaphore may silently ignore them.');
        }

        if (
            $validated['message'] !== '' &&
            (
                stripos($validated['message'], 'diagnosed with') !== false ||
                stripos($validated['message'], 'positive for') !== false ||
                stripos($validated['message'], 'hiv') !== false ||
                stripos($validated['message'], 'std') !== false
            )
        ) {
            throw new RuntimeException('Avoid sensitive diagnosis details in SMS. Use a neutral reminder instead.');
        }

        if (
            isset($validated['age_min'], $validated['age_max']) &&
            (int) $validated['age_min'] > (int) $validated['age_max']
        ) {
            throw new RuntimeException('Minimum age cannot be greater than maximum age.');
        }

        return $validated;
    }

    private function resolveRecipients(array $validated, ?User $requester = null): array
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
                'barangay' => null,
                'gender' => null,
                'age' => null,
                'role' => null,
                'account_status' => null,
                'id_verified' => null,
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

        $primaryKey = Schema::hasColumn('users', 'user_id') ? 'user_id' : 'id';

        $query = User::query()
            ->with('role')
            ->whereNotNull($mobileColumn)
            ->where($mobileColumn, '!=', '');

        $filters = array_merge(
            $validated['target_filters'] ?? [],
            $validated['filters'] ?? [],
            $validated
        );

        if ($mode === 'barangay' && empty($filters['barangay'])) {
            throw new RuntimeException('Barangay is required for barangay SMS mode.');
        }

        $this->applyEligibleResidentScope($query);

        if (Schema::hasColumn('users', 'account_status')) {
            $query->where('account_status', 'active');
        } elseif (Schema::hasColumn('users', 'status')) {
            $query->where('status', 'active');
        } elseif (!empty($filters['account_status']) && $filters['account_status'] !== 'all') {
            $query->whereRaw('1 = 0');
        }

        if (!empty($filters['account_status']) && $filters['account_status'] !== 'all') {
            if (Schema::hasColumn('users', 'account_status')) {
                $query->where('account_status', $filters['account_status']);
            } elseif (Schema::hasColumn('users', 'status')) {
                $query->where('status', $filters['account_status']);
            }
        }

        $requestedRhu = isset($filters['rhu_id']) ? (int) $filters['rhu_id'] : null;
        $effectiveRhu = Rhu::filterRhuId($requester, $requestedRhu);

        if ($effectiveRhu !== null) {
            $this->applyRhuScope($query, $effectiveRhu, $primaryKey);
        }

        $role = $filters['role'] ?? null;

        if (in_array($mode, ['patient', 'patients'], true)) {
            $role = 'patient';
        }

        if ($role) {
            $this->applyRoleFilter($query, (string) $role);
        }

        if (!empty($filters['barangay'])) {
            $this->applyBarangayFilter($query, (string) $filters['barangay'], $primaryKey);
        }

        $gender = $filters['gender'] ?? $filters['sex'] ?? null;

        if (!empty($gender) && $gender !== 'all') {
            $this->applyGenderFilter($query, (string) $gender, $primaryKey);
        }

        if (isset($filters['age_min']) || isset($filters['age_max'])) {
            $this->applyAgeFilter(
                $query,
                isset($filters['age_min']) ? (int) $filters['age_min'] : null,
                isset($filters['age_max']) ? (int) $filters['age_max'] : null,
                $primaryKey
            );
        }

        if (array_key_exists('id_verified', $filters) && $filters['id_verified'] !== null && $filters['id_verified'] !== '') {
            $this->applyIdVerifiedFilter(
                $query,
                filter_var($filters['id_verified'], FILTER_VALIDATE_BOOLEAN),
                $primaryKey
            );
        }

        $limit = min(max((int) ($validated['limit'] ?? 1000), 1), 1000);

        return $query
            ->limit($limit)
            ->get()
            ->map(function ($user) use ($mobileColumn, $primaryKey) {
                $normalized = $this->semaphore->normalizePhoneNumber((string) $user->{$mobileColumn});

                if (!$normalized) {
                    return null;
                }

                $name = trim(implode(' ', array_filter([
                    $user->first_name ?? null,
                    $user->last_name ?? null,
                ])));

                if ($name === '') {
                    $name = $user->name ?? $user->full_name ?? 'Recipient';
                }

                return [
                    'user_id' => $user->{$primaryKey} ?? null,
                    'recipient_name' => $name,
                    'name' => $name,
                    'mobile_number' => $normalized,
                    'barangay' => $user->barangay ?? $user->barangay_name ?? null,
                    'gender' => $user->gender ?? $user->sex ?? null,
                    'age' => $user->age ?? null,
                    'role' => $user->role?->name ?? $user->role_name ?? null,
                    'account_status' => $user->account_status ?? $user->status ?? null,
                    'id_verified' => $user->id_verified ?? $user->is_verified ?? $user->verified ?? null,
                ];
            })
            ->filter()
            ->unique('mobile_number')
            ->values()
            ->all();
    }

    private function applyRoleFilter($query, string $role): void
    {
        $role = strtolower(str_replace([' ', '-'], '_', trim($role)));

        if (Schema::hasColumn('users', 'role_id')) {
            $roleId = $this->resolveRoleId($role);

            if ($roleId) {
                $query->where('role_id', $roleId);
            } else {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        foreach (['role', 'role_name', 'account_type', 'user_role'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $query->whereRaw("LOWER(REPLACE({$column}, ' ', '_')) = ?", [$role]);
                return;
            }
        }
    }

    private function applyEligibleResidentScope($query): void
    {
        $residentRoles = ['resident', 'patient'];

        if (Schema::hasColumn('users', 'role_id')) {
            $roleIds = collect($residentRoles)
                ->map(fn (string $role) => $this->resolveRoleId($role))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (count($roleIds) > 0) {
                $query->whereIn('role_id', $roleIds);
            } else {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        foreach (['role', 'role_name', 'account_type', 'user_role'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $query->whereIn(
                    DB::raw("LOWER(REPLACE(REPLACE({$column}, ' ', '_'), '-', '_'))"),
                    $residentRoles
                );
                return;
            }
        }
    }

    private function applyBarangayFilter($query, string $barangay, string $primaryKey): void
    {
        $operator = $this->textOperator();
        $barangay = trim($barangay);

        if ($barangay === '') {
            return;
        }

        if (Schema::hasColumn('users', 'barangay')) {
            $query->where('barangay', $operator, "%{$barangay}%");
            return;
        }

        if (Schema::hasColumn('users', 'barangay_name')) {
            $query->where('barangay_name', $operator, "%{$barangay}%");
            return;
        }

        if (Schema::hasColumn('users', 'barangay_id')) {
            $ids = $this->resolveBarangayIds($barangay);

            if (count($ids) > 0) {
                $query->whereIn('barangay_id', $ids);
            } elseif (is_numeric($barangay)) {
                $query->where('barangay_id', (int) $barangay);
            } else {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            $ids = $this->resolveBarangayIds($barangay);

            if (count($ids) === 0 && is_numeric($barangay)) {
                $ids = [(int) $barangay];
            }

            if (count($ids) > 0) {
                $query->whereIn($primaryKey, function ($sub) use ($ids) {
                    $sub->select('user_id')
                        ->from('resident_profiles')
                        ->whereIn('barangay_id', $ids);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }
    }

    private function applyRhuScope($query, int $rhuId, string $primaryKey): void
    {
        if (!Schema::hasTable('barangays') || !Schema::hasColumn('barangays', 'rhu_id')) {
            if ($rhuId !== Rhu::DEFAULT_ID) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $barangayKey = Schema::hasColumn('barangays', 'barangay_id') ? 'barangay_id' : 'id';
        $hasAnyPath = false;

        $hasResidentProfilePath =
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id');

        $query->where(function ($outer) use ($barangayKey, $rhuId, $primaryKey, $hasResidentProfilePath, &$hasAnyPath) {
            if (Schema::hasColumn('users', 'barangay_id')) {
                $hasAnyPath = true;
                $outer->orWhereIn('barangay_id', function ($sub) use ($barangayKey, $rhuId) {
                    $sub->select($barangayKey)
                        ->from('barangays')
                        ->where('rhu_id', $rhuId);
                });
            }

            if (Schema::hasColumn('users', 'barangay')) {
                $hasAnyPath = true;
                $outer->orWhereIn('barangay', function ($sub) use ($rhuId) {
                    $sub->select('name')
                        ->from('barangays')
                        ->where('rhu_id', $rhuId);
                });
            }

            if (Schema::hasColumn('users', 'barangay_name')) {
                $hasAnyPath = true;
                $outer->orWhereIn('barangay_name', function ($sub) use ($rhuId) {
                    $sub->select('name')
                        ->from('barangays')
                        ->where('rhu_id', $rhuId);
                });
            }

            if ($hasResidentProfilePath) {
                $hasAnyPath = true;
                $outer->orWhereIn($primaryKey, function ($sub) use ($barangayKey, $rhuId) {
                    $sub->select('user_id')
                        ->from('resident_profiles')
                        ->whereIn('barangay_id', function ($inner) use ($barangayKey, $rhuId) {
                            $inner->select($barangayKey)
                                ->from('barangays')
                                ->where('rhu_id', $rhuId);
                        });
                });
            }

            if (!$hasAnyPath) {
                $outer->whereRaw('1 = 0');
            }
        });
    }

    private function applyGenderFilter($query, string $gender, string $primaryKey): void
    {
        $gender = strtolower(trim($gender));

        foreach (['gender', 'sex'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $query->whereRaw("LOWER({$column}) = ?", [$gender]);
                return;
            }
        }

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id')
        ) {
            foreach (['gender', 'sex'] as $column) {
                if (Schema::hasColumn('resident_profiles', $column)) {
                    $query->whereIn($primaryKey, function ($sub) use ($column, $gender) {
                        $sub->select('user_id')
                            ->from('resident_profiles')
                            ->whereRaw("LOWER({$column}) = ?", [$gender]);
                    });
                    return;
                }
            }
        }
    }

    private function applyAgeFilter($query, ?int $min, ?int $max, string $primaryKey): void
    {
        if (Schema::hasColumn('users', 'age')) {
            if ($min !== null) {
                $query->where('age', '>=', $min);
            }

            if ($max !== null) {
                $query->where('age', '<=', $max);
            }

            return;
        }

        $birthdateColumn = $this->firstExistingColumn('users', [
            'birthdate',
            'date_of_birth',
            'birthday',
        ]);

        if ($birthdateColumn) {
            if ($min !== null) {
                $maxBirthdate = now()->subYears($min)->toDateString();
                $query->whereDate($birthdateColumn, '<=', $maxBirthdate);
            }

            if ($max !== null) {
                $minBirthdate = now()->subYears($max + 1)->addDay()->toDateString();
                $query->whereDate($birthdateColumn, '>=', $minBirthdate);
            }

            return;
        }

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id')
        ) {
            if (Schema::hasColumn('resident_profiles', 'age')) {
                $query->whereIn($primaryKey, function ($sub) use ($min, $max) {
                    $sub->select('user_id')->from('resident_profiles');

                    if ($min !== null) {
                        $sub->where('age', '>=', $min);
                    }

                    if ($max !== null) {
                        $sub->where('age', '<=', $max);
                    }
                });

                return;
            }

            $profileBirthdate = $this->firstExistingColumn('resident_profiles', [
                'birthdate',
                'date_of_birth',
                'birthday',
            ]);

            if ($profileBirthdate) {
                $query->whereIn($primaryKey, function ($sub) use ($profileBirthdate, $min, $max) {
                    $sub->select('user_id')->from('resident_profiles');

                    if ($min !== null) {
                        $maxBirthdate = now()->subYears($min)->toDateString();
                        $sub->whereDate($profileBirthdate, '<=', $maxBirthdate);
                    }

                    if ($max !== null) {
                        $minBirthdate = now()->subYears($max + 1)->addDay()->toDateString();
                        $sub->whereDate($profileBirthdate, '>=', $minBirthdate);
                    }
                });
            }
        }
    }

    private function applyIdVerifiedFilter($query, bool $verified, string $primaryKey): void
    {
        foreach (['id_verified', 'is_verified', 'verified'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $query->where($column, $verified);
                return;
            }
        }

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id')
        ) {
            foreach (['id_verified', 'is_verified', 'verified'] as $column) {
                if (Schema::hasColumn('resident_profiles', $column)) {
                    $query->whereIn($primaryKey, function ($sub) use ($column, $verified) {
                        $sub->select('user_id')
                            ->from('resident_profiles')
                            ->where($column, $verified);
                    });
                    return;
                }
            }
        }
    }

    private function resolveBarangayIds(string $barangay): array
    {
        if (!Schema::hasTable('barangays')) {
            return [];
        }

        $operator = $this->textOperator();
        $key = Schema::hasColumn('barangays', 'barangay_id') ? 'barangay_id' : 'id';

        $query = DB::table('barangays')->select($key);

        if (is_numeric($barangay)) {
            $query->where($key, (int) $barangay);
        } elseif (Schema::hasColumn('barangays', 'name')) {
            $query->where('name', $operator, "%{$barangay}%");
        } elseif (Schema::hasColumn('barangays', 'barangay_name')) {
            $query->where('barangay_name', $operator, "%{$barangay}%");
        } else {
            return [];
        }

        return $query
            ->pluck($key)
            ->map(fn ($id) => (int) $id)
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
            'failed', 'error', 'undelivered', 'refunded', 'false' => 'failed',
            default => $value ?: 'queued',
        };
    }

    private function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return match ($mode) {
            '', 'manual', 'single' => 'single',
            'all', 'bulk' => 'all',
            'barangay', 'brgy' => 'barangay',
            'patient', 'patients' => 'patients',
            'custom', 'targeted', 'demographic', 'demographics', 'filter', 'filtered' => 'custom',
            default => $mode,
        };
    }

    private function estimateCredits(string $message, int $recipientCount): int
    {
        if (trim($message) === '') {
            return 0;
        }

        $segments = max(1, (int) ceil(max(strlen($message), 1) / 160));

        return $segments * max(0, $recipientCount);
    }

    private function targetFilters(array $validated): array
    {
        return [
            'mode' => $validated['mode'] ?? 'single',
            'provider' => $this->semaphore->providerName(),
            'rhu_id' => $validated['rhu_id'] ?? null,
            'role' => $validated['role'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'gender' => $validated['gender'] ?? $validated['sex'] ?? null,
            'age_min' => $validated['age_min'] ?? null,
            'age_max' => $validated['age_max'] ?? null,
            'age_group' => $validated['age_group'] ?? null,
            'account_status' => $validated['account_status'] ?? null,
            'id_verified' => $validated['id_verified'] ?? null,
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
        if (!Schema::hasTable($table)) {
            return null;
        }

        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function textOperator(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function authorizeSms(Request $request): void
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        $allowedRoles = [
            'mho',
            'super_admin',
            'superadmin',
            'admin',
            'rhu_admin',
            'staff_admin',
            'staff',
            'nurse',
            'midwife',
        ];

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($allowedRoles)) {
            return;
        }

        $role = strtolower(trim((string) (
            $user->role
            ?? $user->role_name
            ?? $user->user_role
            ?? $user->account_type
            ?? ''
        )));

        abort_unless(
            in_array($role, $allowedRoles, true),
            403,
            'You are not allowed to use the SMS module.'
        );
    }
}
