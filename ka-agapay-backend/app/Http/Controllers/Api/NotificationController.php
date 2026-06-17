<?php
// app/Http/Controllers/Api/NotificationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     *
     * Paginated in-app notification inbox for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:50'],
            'unread_only' => ['nullable', 'boolean'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $unreadOnly = (bool) ($validated['unread_only'] ?? false);

        $query = $request->user()
            ->notifications()
            ->latest();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate($perPage);

        return response()->json([
            'data' => collect($notifications->items())
                ->map(fn ($notification) => $this->formatNotification($notification))
                ->values(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'pagination' => [
                'total' => $notifications->total(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/notifications/unread-count
     *
     * Lightweight endpoint for notification badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * PATCH /api/v1/notifications/{id}/read
     *
     * Mark a single notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->firstOrFail();

        if (is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /api/v1/notifications/read-all
     *
     * Mark all unread notifications as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'unread_count' => 0,
        ]);
    }

    /**
     * DELETE /api/v1/notifications/{id}
     *
     * Delete a single notification owned by the authenticated user.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()
            ->notifications()
            ->where('id', $id)
            ->firstOrFail()
            ->delete();

        return response()->json([
            'message' => 'Notification deleted.',
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * GET /api/v1/notifications/preferences
     *
     * Get user's notification preferences.
     */
    public function preferences(Request $request): JsonResponse
    {
        $userId = (int) ($request->user()->user_id ?? $request->user()->id);

        $prefs = NotificationPreference::where('user_id', $userId)
            ->orderBy('notification_type')
            ->get();

        return response()->json([
            'data' => $prefs,
        ]);
    }

    /**
     * PUT /api/v1/notifications/preferences
     *
     * Update user's notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.notification_type' => ['required', 'string', 'max:100'],
            'preferences.*.in_app' => ['sometimes', 'boolean'],
            'preferences.*.sms' => ['sometimes', 'boolean'],
            'preferences.*.email' => ['sometimes', 'boolean'],
        ]);

        $userId = (int) ($request->user()->user_id ?? $request->user()->id);

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

    private function formatNotification(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? $data['body'] ?? '',
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'action_url' => $data['action_url'] ?? $data['url'] ?? null,
            'action_type' => $data['action_type'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'data' => $data,
        ];
    }
}