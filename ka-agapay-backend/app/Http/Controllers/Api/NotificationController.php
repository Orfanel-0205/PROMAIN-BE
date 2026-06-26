<?php
// app/Http/Controllers/Api/NotificationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:50'],
            'unread_only' => ['nullable', 'boolean'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $unreadOnly = (bool) ($validated['unread_only'] ?? false);

        $query = $this->notificationQuery($request->user())
            ->orderByDesc('created_at');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate($perPage);

        return response()->json([
            'data' => collect($notifications->items())
                ->map(fn ($notification) => $this->formatRawNotification((object) $notification))
                ->values(),
            'unread_count' => $this->unreadCountFor($request->user()),
            'pagination' => [
                'total' => $notifications->total(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->unreadCountFor($request->user()),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationQuery($request->user())
            ->where('id', $id)
            ->first();

        abort_if(!$notification, 404, 'Notification not found.');

        if (is_null($notification->read_at)) {
            DB::table('notifications')
                ->where('id', $id)
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'unread_count' => $this->unreadCountFor($request->user()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->notificationQuery($request->user())
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'unread_count' => 0,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = $this->notificationQuery($request->user())
            ->where('id', $id)
            ->first();

        abort_if(!$notification, 404, 'Notification not found.');

        DB::table('notifications')
            ->where('id', $id)
            ->delete();

        return response()->json([
            'message' => 'Notification deleted.',
            'unread_count' => $this->unreadCountFor($request->user()),
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $userId = $this->userKey($request->user());

        $prefs = NotificationPreference::where('user_id', $userId)
            ->orderBy('notification_type')
            ->get();

        return response()->json([
            'data' => $prefs,
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.notification_type' => ['required', 'string', 'max:100'],
            'preferences.*.in_app' => ['sometimes', 'boolean'],
            'preferences.*.sms' => ['sometimes', 'boolean'],
            'preferences.*.email' => ['sometimes', 'boolean'],
        ]);

        $userId = $this->userKey($request->user());

        DB::transaction(function () use ($validated, $userId) {
            foreach ($validated['preferences'] as $pref) {
                NotificationPreference::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'notification_type' => $pref['notification_type'],
                    ],
                    [
                        'in_app' => $pref['in_app'] ?? true,
                        'sms' => $pref['sms'] ?? false,
                        'email' => $pref['email'] ?? false,
                    ]
                );
            }
        });

        return response()->json([
            'message' => 'Notification preferences updated.',
        ]);
    }

    public function storeDeviceToken(Request $request): JsonResponse
    {
        if (!Schema::hasTable('user_device_tokens')) {
            Log::warning('[Push] device token endpoint hit but user_device_tokens table is missing.');

            return response()->json([
                'message' => 'Device token table is not ready.',
            ], 500);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $request->merge([
            'token' => $request->input('token') ?: $request->input('expo_push_token'),
        ]);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'expo_push_token' => ['nullable', 'string', 'max:500'],
            'provider' => ['nullable', 'string', 'max:30'],
            'platform' => ['nullable', 'string', 'max:30'],
            'device_name' => ['nullable', 'string', 'max:150'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'channel_id' => ['nullable', 'string', 'max:80'],
        ]);

        $token = trim((string) $validated['token']);
        $provider = strtolower(trim((string) ($validated['provider'] ?? 'expo'))) ?: 'expo';
        $platform = strtolower(trim((string) ($validated['platform'] ?? ''))) ?: null;
        $userId = $this->userKey($user);

        if (!$this->looksLikeSupportedToken($token, $provider)) {
            Log::warning('[Push] invalid device token rejected', [
                'user_id' => $userId,
                'provider' => $provider,
                'platform' => $platform,
                'token_prefix' => substr($token, 0, 18),
            ]);

            return response()->json([
                'message' => 'Invalid device token.',
            ], 422);
        }

        Log::info('[Push] device token endpoint hit', [
            'user_id' => $userId,
            'provider' => $provider,
            'platform' => $platform,
            'token_prefix' => substr($token, 0, 18),
        ]);

        try {
            $values = [
                'user_id' => $userId,
                'provider' => $provider,
                'platform' => $platform,
                'device_name' => $validated['device_name'] ?? null,
                'is_active' => true,
                'last_seen_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('user_device_tokens', 'app_version')) {
                $values['app_version'] = $validated['app_version'] ?? null;
            }

            if (Schema::hasColumn('user_device_tokens', 'channel_id')) {
                $values['channel_id'] = $validated['channel_id'] ?? null;
            }

            if (Schema::hasColumn('user_device_tokens', 'failed_at')) {
                $values['failed_at'] = null;
            }

            if (Schema::hasColumn('user_device_tokens', 'failure_reason')) {
                $values['failure_reason'] = null;
            }

            $deviceToken = UserDeviceToken::updateOrCreate(
                ['token' => $token],
                $values
            );

            $activeTokensCount = UserDeviceToken::query()
                ->where('user_id', $userId)
                ->where('provider', $provider)
                ->where('is_active', true)
                ->count();

            Log::info('[Push] device token saved', [
                'user_id' => $userId,
                'provider' => $provider,
                'platform' => $platform,
                'token_prefix' => substr($token, 0, 18),
                'device_token_id' => $deviceToken->id,
                'active_tokens_count' => $activeTokensCount,
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Device token saved.',
                'device_token' => [
                    'id' => $deviceToken->id,
                    'user_id' => $deviceToken->user_id,
                    'provider' => $deviceToken->provider,
                    'platform' => $deviceToken->platform,
                    'device_name' => $deviceToken->device_name,
                    'is_active' => (bool) $deviceToken->is_active,
                    'last_seen_at' => optional($deviceToken->last_seen_at)->toIso8601String(),
                    'token_prefix' => substr($token, 0, 18),
                ],
                'active_tokens_count' => $activeTokensCount,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Push] device token save failed', [
                'user_id' => $userId,
                'provider' => $provider,
                'platform' => $platform,
                'token_prefix' => substr($token, 0, 18),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function notificationQuery(User $user)
    {
        $ids = array_values(array_unique(array_filter([
            $user->getKey(),
            $user->user_id ?? null,
            $user->id ?? null,
        ])));

        return DB::table('notifications')
            ->whereIn('notifiable_type', [
                User::class,
                get_class($user),
                'App\\Models\\User',
            ])
            ->whereIn('notifiable_id', $ids);
    }

    private function unreadCountFor(User $user): int
    {
        return (int) $this->notificationQuery($user)
            ->whereNull('read_at')
            ->count();
    }

    private function userKey(User $user): int
    {
        return (int) (
            $user->getKey()
            ?: ($user->user_id ?? $user->id ?? 0)
        );
    }

    private function formatRawNotification(object $notification): array
    {
        $data = $this->decodeData($notification->data ?? []);

        return [
            'id' => $notification->id,
            'type' => $data['notification_type']
                ?? $data['type']
                ?? $notification->type
                ?? 'notification',
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? $data['body'] ?? '',
            'read_at' => $notification->read_at
                ? date('c', strtotime((string) $notification->read_at))
                : null,
            'created_at' => $notification->created_at
                ? date('c', strtotime((string) $notification->created_at))
                : null,
            'action_url' => $data['action_url'] ?? $data['url'] ?? null,
            'action_type' => $data['action_type'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'data' => $data,
        ];
    }

    private function decodeData(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_object($data)) {
            return (array) $data;
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function looksLikeSupportedToken(string $token, string $provider): bool
    {
        if ($provider === 'expo') {
            return str_starts_with($token, 'ExponentPushToken[')
                || str_starts_with($token, 'ExpoPushToken[');
        }

        return trim($token) !== '';
    }
}