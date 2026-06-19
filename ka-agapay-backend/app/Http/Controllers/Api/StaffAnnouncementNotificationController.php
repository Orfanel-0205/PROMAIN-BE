<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StaffAnnouncementNotificationController extends Controller
{
    private array $staffRoles = [
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'admin',
        'rhu_admin',
        'mho',
        'municipal_mayor',
        'it_staff',
        'super_admin',
        'superadmin',
    ];

    public function notify(Request $request, int $announcementId): JsonResponse
    {
        $announcement = Announcement::findOrFail($announcementId);

        $validated = $request->validate([
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:50'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $roles = collect($validated['roles'] ?? $this->staffRoles)
            ->map(fn ($role) => strtolower(str_replace([' ', '-'], '_', trim((string) $role))))
            ->filter()
            ->values()
            ->all();

        if (empty($roles)) {
            $roles = $this->staffRoles;
        }

        $users = User::query()
            ->with('role')
            ->where('account_status', 'active')
            ->whereHas('role', function ($query) use ($roles) {
                $query->whereIn('name', $roles);
            })
            ->get();

        $message = $validated['message']
            ?? $announcement->body
            ?? $announcement->description
            ?? 'New RHU announcement posted.';

        $count = DB::transaction(function () use ($request, $announcement, $users, $message) {
            $count = 0;

            foreach ($users as $user) {
                if (Schema::hasTable('staff_announcement_notifications')) {
                    DB::table('staff_announcement_notifications')->insert([
                        'announcement_id' => $announcement->id,
                        'user_id' => $user->user_id,
                        'role' => $user->role?->name,
                        'title' => $announcement->title,
                        'message' => $message,
                        'read_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if (Schema::hasTable('notifications')) {
                    $payload = [
                        'announcement_id' => $announcement->id,
                        'title' => $announcement->title,
                        'message' => $message,
                        'category' => $announcement->category ?? 'general',
                        'audience' => 'staff',
                        'url' => '/cms',
                    ];

                    $row = [
                        'type' => 'staff_announcement',
                        'notifiable_type' => User::class,
                        'notifiable_id' => $user->user_id,
                        'data' => json_encode($payload),
                        'read_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (Schema::hasColumn('notifications', 'id')) {
                        $row['id'] = (string) Str::uuid();
                    }

                    DB::table('notifications')->insert($row);
                }

                $count++;
            }

            $updates = [];

            if (Schema::hasColumn('announcements', 'notify_staff')) {
                $updates['notify_staff'] = true;
            }

            if (Schema::hasColumn('announcements', 'staff_notified_at')) {
                $updates['staff_notified_at'] = now();
            }

            if (Schema::hasColumn('announcements', 'staff_notified_by')) {
                $updates['staff_notified_by'] = $request->user()?->user_id;
            }

            if (Schema::hasColumn('announcements', 'staff_notifications_count')) {
                $updates['staff_notifications_count'] = $count;
            }

            if (!empty($updates)) {
                $announcement->update($updates);
            }

            return $count;
        });

        return response()->json([
            'message' => "Staff notification sent to {$count} RHU staff account(s).",
            'notified_count' => $count,
            'data' => $announcement->fresh(),
        ]);
    }
}