<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     * Paginated in-app notification inbox for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data'         => $notifications->items(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'pagination'   => [
                'total'        => $notifications->total(),
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * PATCH /api/v1/notifications/{id}/read
     * Mark a single notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    /**
     * POST /api/v1/notifications/read-all
     * Mark all unread notifications as read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * GET /api/v1/notifications/unread-count
     * Lightweight endpoint for notification badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * GET /api/v1/notifications/preferences
     * Get user's notification preferences.
     */
    public function preferences(Request $request): JsonResponse
    {
        $prefs = NotificationPreference::where('user_id', $request->user()->user_id)->get();

        return response()->json(['data' => $prefs]);
    }

    /**
     * PUT /api/v1/notifications/preferences
     * Update user's notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences'                  => ['required', 'array'],
            'preferences.*.notification_type' => ['required', 'string', 'max:100'],
            'preferences.*.in_app'         => ['sometimes', 'boolean'],
            'preferences.*.sms'            => ['sometimes', 'boolean'],
            'preferences.*.email'          => ['sometimes', 'boolean'],
        ]);

        foreach ($validated['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id'           => $request->user()->user_id,
                    'notification_type' => $pref['notification_type'],
                ],
                [
                    'in_app' => $pref['in_app'] ?? true,
                    'sms'    => $pref['sms'] ?? false,
                    'email'  => $pref['email'] ?? false,
                ]
            );
        }

        return response()->json(['message' => 'Notification preferences updated.']);
    }

    /**
     * DELETE /api/v1/notifications/{id}
     * Delete a single notification.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
