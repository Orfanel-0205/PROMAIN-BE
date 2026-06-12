<?php
// app/Http/Controllers/Api/AdminSmsController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsLog;
use App\Services\Sms\SemaphoreSmsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminSmsController extends Controller
{
    public function __construct(
        private readonly SemaphoreSmsService $semaphore
    ) {}

    /**
     * GET /api/v1/admin/sms/account
     *
     * Backend-only rate limit:
     * 2 account checks per minute per authenticated user.
     */
    public function account(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $userId = $request->user()?->user_id ?? $request->ip();
        $rateKey = 'sms-account-check:' . $userId;

        if (RateLimiter::tooManyAttempts($rateKey, 2)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'configured' => $this->semaphore->isConfigured(),
                'message' => "Please wait {$seconds} second(s) before checking Semaphore credits again.",
                'account' => null,
                'credit_balance' => null,
            ], 429);
        }

        RateLimiter::hit($rateKey, 60);

        try {
            $account = $this->semaphore->account();

            return response()->json([
                'configured' => true,
                'account' => $account,
                'credit_balance' => $account['credit_balance'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'configured' => $this->semaphore->isConfigured(),
                'message' => $e->getMessage(),
                'account' => null,
                'credit_balance' => null,
            ], 422);
        }
    }

    /**
     * GET /api/v1/admin/sms/logs
     */
    public function logs(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = SmsLog::query()
            ->with(['user:user_id,first_name,last_name,mobile_number'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->query('search');

                $query->where(function ($q) use ($search) {
                    $q->where('mobile_number', 'like', "%{$search}%")
                        ->orWhere('recipient_name', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status') && $request->query('status') !== 'all', function ($query) use ($request) {
                $query->where('status', $request->query('status'));
            })
            ->latest()
            ->paginate($request->integer('per_page', 50));

        return response()->json($logs);
    }

    /**
     * POST /api/v1/admin/sms/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $validated = $this->validateSmsPayload($request, preview: true);

        $recipients = $this->resolveRecipients($validated)
            ->take(1000)
            ->values();

        return response()->json([
            'count' => $recipients->count(),
            'recipients' => $recipients,
            'estimated_credits' => $this->estimateCredits(
                $recipients->count(),
                (string) ($validated['message'] ?? '')
            ),
        ]);
    }

    /**
     * POST /api/v1/admin/sms/send
     */
    public function send(Request $request): JsonResponse
    {
        $this->authorizeSms($request);

        $validated = $this->validateSmsPayload($request);

        $message = trim((string) $validated['message']);

        $recipients = $this->resolveRecipients($validated)
            ->take(1000)
            ->values();

        if ($recipients->isEmpty()) {
            return response()->json([
                'message' => 'No eligible SMS recipients found.',
            ], 422);
        }

        $numbers = $recipients
            ->pluck('mobile_number')
            ->filter()
            ->unique()
            ->values()
            ->all();

        DB::beginTransaction();

        try {
            $logs = [];

            foreach ($recipients as $recipient) {
                $logs[] = SmsLog::create([
                    'user_id' => $recipient['user_id'] ?? null,
                    'sent_by' => $request->user()?->user_id,
                    'recipient_name' => $recipient['name'] ?? null,
                    'mobile_number' => $recipient['mobile_number'],
                    'message' => $message,
                    'mode' => $validated['mode'],
                    'target_filters' => $this->filtersForLog($validated),
                    'notification_type' => $validated['notification_type'] ?? 'manual',
                    'provider' => 'semaphore',
                    'status' => 'queued',
                ]);
            }

            $providerResponse = $this->semaphore->sendBulk($numbers, $message);

            $providerItems = collect($providerResponse);

            foreach ($logs as $log) {
                $matching = $providerItems->first(function ($item) use ($log) {
                    $recipient = $item['recipient'] ?? $item['number'] ?? null;

                    if (!$recipient) {
                        return false;
                    }

                    return str_contains(
                        (string) $recipient,
                        substr($log->mobile_number, -10)
                    );
                });

                $status = strtolower((string) ($matching['status'] ?? 'sent'));

                $log->update([
                    'provider_message_id' => $matching['message_id'] ?? null,
                    'status' => $this->normalizeProviderStatus($status),
                    'sent_at' => now(),
                    'error_message' => $status === 'failed'
                        ? json_encode($matching)
                        : null,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'SMS sent through Semaphore.',
                'count' => $recipients->count(),
                'estimated_credits' => $this->estimateCredits($recipients->count(), $message),
                'provider_response' => $providerResponse,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            foreach ($recipients as $recipient) {
                SmsLog::create([
                    'user_id' => $recipient['user_id'] ?? null,
                    'sent_by' => $request->user()?->user_id,
                    'recipient_name' => $recipient['name'] ?? null,
                    'mobile_number' => $recipient['mobile_number'] ?? '',
                    'message' => $message,
                    'mode' => $validated['mode'],
                    'target_filters' => $this->filtersForLog($validated),
                    'notification_type' => $validated['notification_type'] ?? 'manual',
                    'provider' => 'semaphore',
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Failed to send SMS.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function validateSmsPayload(Request $request, bool $preview = false): array
    {
        return $request->validate([
            'mode' => ['required', Rule::in(['single', 'barangay', 'all', 'custom'])],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'message' => [$preview ? 'nullable' : 'required', 'string', 'max:640'],
            'notification_type' => ['nullable', 'string', 'max:100'],

            'sex' => ['nullable', 'string', 'max:30'],
            'age_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'age_max' => ['nullable', 'integer', 'min:0', 'max:120'],
            'account_status' => ['nullable', 'string', 'max:50'],
            'id_verified' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
    }

    private function resolveRecipients(array $filters)
    {
        $mode = $filters['mode'];

        if ($mode === 'single') {
            $normalized = $this->semaphore->normalizePhoneNumber(
                (string) ($filters['mobile_number'] ?? '')
            );

            if (!$normalized) {
                return collect();
            }

            return collect([
                [
                    'user_id' => null,
                    'name' => 'Manual Recipient',
                    'mobile_number' => $normalized,
                    'barangay' => null,
                    'sex' => null,
                    'age' => null,
                    'account_status' => null,
                    'id_verified' => null,
                ],
            ]);
        }

        if (!Schema::hasTable('users')) {
            return collect();
        }

        $query = DB::table('users')
            ->select([
                'users.user_id',
                'users.first_name',
                'users.last_name',
                'users.mobile_number',
                'users.account_status',
            ])
            ->whereNotNull('users.mobile_number')
            ->where('users.mobile_number', '!=', '');

        if (Schema::hasColumn('users', 'id_verified')) {
            $query->addSelect('users.id_verified');
        }

        if (Schema::hasTable('resident_profiles')) {
            $query->leftJoin('resident_profiles', 'resident_profiles.user_id', '=', 'users.user_id');

            if (Schema::hasColumn('resident_profiles', 'sex')) {
                $query->addSelect('resident_profiles.sex as profile_sex');
            }

            if (Schema::hasColumn('resident_profiles', 'birth_date')) {
                $query->addSelect('resident_profiles.birth_date');
            }

            if (Schema::hasColumn('resident_profiles', 'barangay_id')) {
                $query->addSelect('resident_profiles.barangay_id as profile_barangay_id');
            }
        }

        if (Schema::hasColumn('users', 'barangay')) {
            $query->addSelect('users.barangay as user_barangay');
        }

        if (Schema::hasColumn('users', 'sex')) {
            $query->addSelect('users.sex as user_sex');
        }

        if (Schema::hasColumn('users', 'birthday')) {
            $query->addSelect('users.birthday');
        }

        if (Schema::hasColumn('users', 'barangay_id')) {
            $query->addSelect('users.barangay_id as user_barangay_id');
        }

        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', function ($join) {
                if (Schema::hasColumn('users', 'barangay_id')) {
                    $join->on('barangays.barangay_id', '=', 'users.barangay_id');
                } elseif (
                    Schema::hasTable('resident_profiles')
                    && Schema::hasColumn('resident_profiles', 'barangay_id')
                ) {
                    $join->on('barangays.barangay_id', '=', 'resident_profiles.barangay_id');
                }
            });

            if (Schema::hasColumn('barangays', 'name')) {
                $query->addSelect('barangays.name as barangay_name');
            }
        }

        if (!empty($filters['account_status']) && $filters['account_status'] !== 'all') {
            $query->where('users.account_status', $filters['account_status']);
        } else {
            $query->whereIn('users.account_status', ['active', 'pending']);
        }

        if (
            array_key_exists('id_verified', $filters)
            && $filters['id_verified'] !== null
            && Schema::hasColumn('users', 'id_verified')
        ) {
            $query->where('users.id_verified', (bool) $filters['id_verified']);
        }

        if ($mode === 'barangay' && !empty($filters['barangay'])) {
            $barangay = $filters['barangay'];

            $query->where(function (Builder $q) use ($barangay) {
                if (Schema::hasColumn('users', 'barangay')) {
                    $q->orWhere('users.barangay', 'like', "%{$barangay}%");
                }

                if (Schema::hasTable('barangays') && Schema::hasColumn('barangays', 'name')) {
                    $q->orWhere('barangays.name', 'like', "%{$barangay}%");
                }
            });
        }

        if ($mode === 'custom') {
            if (!empty($filters['barangay'])) {
                $barangay = $filters['barangay'];

                $query->where(function (Builder $q) use ($barangay) {
                    if (Schema::hasColumn('users', 'barangay')) {
                        $q->orWhere('users.barangay', 'like', "%{$barangay}%");
                    }

                    if (Schema::hasTable('barangays') && Schema::hasColumn('barangays', 'name')) {
                        $q->orWhere('barangays.name', 'like', "%{$barangay}%");
                    }
                });
            }

            if (!empty($filters['sex']) && $filters['sex'] !== 'all') {
                $sex = strtolower((string) $filters['sex']);

                $query->where(function (Builder $q) use ($sex) {
                    if (Schema::hasColumn('users', 'sex')) {
                        $q->orWhereRaw('LOWER(users.sex) = ?', [$sex]);
                    }

                    if (Schema::hasTable('resident_profiles') && Schema::hasColumn('resident_profiles', 'sex')) {
                        $q->orWhereRaw('LOWER(resident_profiles.sex) = ?', [$sex]);
                    }
                });
            }
        }

        $limit = min((int) ($filters['limit'] ?? 1000), 1000);

        $rows = $query
            ->limit($limit * 2)
            ->get();

        $recipients = $rows
            ->map(function ($row) {
                $birthDate = $row->birth_date ?? $row->birthday ?? null;
                $age = $birthDate ? now()->diffInYears($birthDate) : null;

                return [
                    'user_id' => $row->user_id,
                    'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                    'mobile_number' => $this->semaphore->normalizePhoneNumber((string) $row->mobile_number),
                    'barangay' => $row->barangay_name ?? $row->user_barangay ?? null,
                    'sex' => $row->profile_sex ?? $row->user_sex ?? null,
                    'age' => $age,
                    'account_status' => $row->account_status ?? null,
                    'id_verified' => isset($row->id_verified) ? (bool) $row->id_verified : null,
                ];
            })
            ->filter(fn ($row) => !empty($row['mobile_number']))
            ->filter(function ($row) use ($filters) {
                if (isset($filters['age_min']) && $filters['age_min'] !== null) {
                    if ($row['age'] === null || $row['age'] < (int) $filters['age_min']) {
                        return false;
                    }
                }

                if (isset($filters['age_max']) && $filters['age_max'] !== null) {
                    if ($row['age'] === null || $row['age'] > (int) $filters['age_max']) {
                        return false;
                    }
                }

                return true;
            })
            ->unique('mobile_number')
            ->take($limit)
            ->values();

        return $recipients;
    }

    private function estimateCredits(int $recipients, string $message): int
    {
        $length = max(strlen($message), 1);
        $segments = (int) ceil($length / 160);

        return $recipients * max($segments, 1);
    }

    private function normalizeProviderStatus(string $status): string
    {
        return match (strtolower($status)) {
            'queued' => 'queued',
            'pending' => 'pending',
            'sent', 'success', 'delivered' => 'sent',
            'failed', 'refunded' => 'failed',
            default => $status ?: 'sent',
        };
    }

    private function filtersForLog(array $validated): array
    {
        return collect($validated)
            ->only([
                'mode',
                'barangay',
                'sex',
                'age_min',
                'age_max',
                'account_status',
                'id_verified',
                'limit',
            ])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }

    private function authorizeSms(Request $request): void
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        $role = $user->role()->first();

        $roleName = strtolower((string) (
            $role?->name
            ?? $role?->role_name
            ?? $role?->slug
            ?? $role?->code
            ?? ''
        ));

        $allowed = [
            'super_admin',
            'superadmin',
            'admin',
            'rhu_admin',
            'staff_admin',
            'staff',
            'mho',
            'doctor',
            'nurse',
            'midwife',
            'bhw',
        ];

        abort_unless(in_array($roleName, $allowed, true), 403, 'You are not allowed to use SMS.');
    }
}