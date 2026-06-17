<?php
// app/Services/Notification/NotificationService.php

namespace App\Services\Notification;

use App\Models\Announcement;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\QueueTicket;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\User;
use App\Notifications\NotificationTypes;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class NotificationService
{
    public function notifyUser(
        User $user,
        string $notificationType,
        string $title,
        string $message,
        array $meta = [],
        ?string $actionUrl = null
    ): ?string {
        if (!Schema::hasTable('notifications')) {
            return null;
        }

        $userId = $this->userKey($user);

        if (!$userId) {
            return null;
        }

        if (!$this->allowsInApp($userId, $notificationType)) {
            return null;
        }

        $notificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => $notificationType,
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'data' => json_encode([
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'notification_type' => $notificationType,
                ...$meta,
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $notificationId;
    }

    public function notifyUsers(
        Collection|EloquentCollection|array $users,
        string $notificationType,
        string $title,
        string $message,
        array $meta = [],
        ?string $actionUrl = null
    ): void {
        foreach ($users as $user) {
            if ($user instanceof User) {
                $this->notifyUser(
                    $user,
                    $notificationType,
                    $title,
                    $message,
                    $meta,
                    $actionUrl
                );
            }
        }
    }

    public function notifyAdmins(
        string $notificationType,
        string $title,
        string $message,
        array $meta = [],
        ?string $actionUrl = null
    ): void {
        if (!Schema::hasTable('users')) {
            return;
        }

        $allowedRoles = [
            'admin',
            'staff',
            'staff_admin',
            'rhu_admin',
            'super_admin',
            'superadmin',
            'mho',
            'doctor',
            'nurse',
            'midwife',
            'bhw',
        ];

        $query = User::query();

        if (Schema::hasColumn('users', 'account_status')) {
            $query->where(function ($q) {
                $q->whereNull('account_status')
                    ->orWhereNotIn('account_status', [
                        'deleted',
                        'inactive',
                        'suspended',
                        'rejected',
                    ]);
            });
        }

        if (Schema::hasColumn('users', 'role')) {
            $query->whereIn('role', $allowedRoles);
        } elseif (Schema::hasColumn('users', 'role_name')) {
            $query->whereIn('role_name', $allowedRoles);
        } elseif (Schema::hasColumn('users', 'user_role')) {
            $query->whereIn('user_role', $allowedRoles);
        } elseif (Schema::hasColumn('users', 'account_type')) {
            $query->whereIn('account_type', $allowedRoles);
        } elseif (Schema::hasColumn('users', 'role_id') && Schema::hasTable('user_roles')) {
            $roleIds = $this->resolveRoleIds($allowedRoles);

            if (count($roleIds) === 0) {
                return;
            }

            $query->whereIn('role_id', $roleIds);
        } else {
            return;
        }

        $this->notifyUsers(
            $query->get(),
            $notificationType,
            $title,
            $message,
            $meta,
            $actionUrl
        );
    }

    public function notifyResidents(
        string $notificationType,
        string $title,
        string $message,
        array $meta = [],
        ?string $actionUrl = null
    ): void {
        if (!Schema::hasTable('users')) {
            return;
        }

        $query = User::query();

        if (Schema::hasColumn('users', 'account_status')) {
            $query->where('account_status', 'active');
        }

        $residentRoles = ['resident', 'patient', 'user'];

        if (Schema::hasColumn('users', 'role')) {
            $query->whereIn('role', $residentRoles);
        } elseif (Schema::hasColumn('users', 'role_name')) {
            $query->whereIn('role_name', $residentRoles);
        } elseif (Schema::hasColumn('users', 'user_role')) {
            $query->whereIn('user_role', $residentRoles);
        } elseif (Schema::hasColumn('users', 'account_type')) {
            $query->whereIn('account_type', $residentRoles);
        } elseif (Schema::hasColumn('users', 'role_id') && Schema::hasTable('user_roles')) {
            $roleIds = $this->resolveRoleIds($residentRoles);

            if (count($roleIds) > 0) {
                $query->whereIn('role_id', $roleIds);
            }
        }

        $this->notifyUsers(
            $query->get(),
            $notificationType,
            $title,
            $message,
            $meta,
            $actionUrl
        );
    }

    public function notifyAppointmentRequestReceived(Appointment $appointment): void
    {
        $appointment->loadMissing('resident');

        $patientName = $this->userName($appointment->resident);

        $reason = $appointment->reason
            ?? $appointment->purpose
            ?? 'Appointment request';

        $this->notifyAdmins(
            NotificationTypes::APPOINTMENT_REQUEST_RECEIVED,
            'New appointment request',
            "{$patientName} submitted an appointment request.",
            [
                'related_type' => 'appointment',
                'related_id' => $appointment->id,
                'patient_name' => $patientName,
                'reason' => $reason,
                'source' => 'mobile',
            ],
            '/appointments'
        );
    }

    public function notifyAppointmentStatus(Appointment $appointment): void
    {
        $appointment->loadMissing('resident');

        $resident = $appointment->resident;

        if (!$resident) {
            return;
        }

        $status = (string) $appointment->status;

        $type = match ($status) {
            'confirmed', 'approved', 'scheduled' => NotificationTypes::APPOINTMENT_CONFIRMED,
            'cancelled' => NotificationTypes::APPOINTMENT_CANCELLED,
            'rejected' => NotificationTypes::APPOINTMENT_REJECTED,
            'completed' => NotificationTypes::APPOINTMENT_COMPLETED,
            default => NotificationTypes::APPOINTMENT_UPDATED,
        };

        $title = match ($status) {
            'confirmed', 'approved', 'scheduled' => 'Appointment approved',
            'cancelled' => 'Appointment cancelled',
            'rejected' => 'Appointment rejected',
            'completed' => 'Appointment completed',
            default => 'Appointment updated',
        };

        $message = match ($status) {
            'confirmed', 'approved', 'scheduled' => 'Your RHU appointment has been approved. Please check your schedule.',
            'cancelled' => 'Your RHU appointment has been cancelled.',
            'rejected' => 'Your RHU appointment request was rejected. Please check the reason or submit another request.',
            'completed' => 'Your RHU appointment has been marked completed.',
            default => "Your appointment status is now {$status}.",
        };

        $this->notifyUser(
            $resident,
            $type,
            $title,
            $message,
            [
                'related_type' => 'appointment',
                'related_id' => $appointment->id,
                'status' => $status,
            ],
            '/appointments'
        );
    }

    public function notifyTelemedicineRequestReceived(TelemedicineRequest $request): void
    {
        $request->loadMissing('residentProfile.user');

        $resident = $request->residentProfile?->user;
        $patientName = $this->userName($resident);

        $this->notifyAdmins(
            NotificationTypes::TELE_REQUEST_RECEIVED,
            'New telemedicine request',
            "{$patientName} requested an online consultation.",
            [
                'related_type' => 'telemedicine',
                'related_id' => $request->id,
                'patient_name' => $patientName,
                'chief_complaint' => $request->chief_complaint,
                'urgency_level' => $request->urgency_level,
                'source' => 'mobile',
            ],
            '/telemedicine'
        );
    }

    public function notifyTelemedicineRequestStatus(TelemedicineRequest $request): void
    {
        $request->loadMissing('residentProfile.user');

        $resident = $request->residentProfile?->user;

        if (!$resident) {
            return;
        }

        $status = (string) $request->status;

        $type = match ($status) {
            'screened' => NotificationTypes::TELE_REQUEST_SCREENED,
            'rejected' => NotificationTypes::TELE_REQUEST_REJECTED,
            'scheduled' => NotificationTypes::TELE_SESSION_SCHEDULED,
            default => NotificationTypes::TELE_REQUEST_SCREENED,
        };

        $title = match ($status) {
            'screened' => 'Telemedicine request screened',
            'rejected' => 'Telemedicine request rejected',
            'scheduled' => 'Telemedicine session scheduled',
            'completed' => 'Telemedicine completed',
            'cancelled' => 'Telemedicine cancelled',
            default => 'Telemedicine request updated',
        };

        $message = match ($status) {
            'screened' => 'Your telemedicine request has been reviewed by RHU staff.',
            'rejected' => 'Your telemedicine request was not approved. Please check the reason or contact RHU staff.',
            'scheduled' => 'Your telemedicine session has been scheduled.',
            'completed' => 'Your telemedicine session has been completed.',
            'cancelled' => 'Your telemedicine request was cancelled.',
            default => "Your telemedicine request status is now {$status}.",
        };

        $this->notifyUser(
            $resident,
            $type,
            $title,
            $message,
            [
                'related_type' => 'telemedicine',
                'related_id' => $request->id,
                'status' => $status,
            ],
            '/telemedicine'
        );
    }

    public function notifySessionScheduled(TelemedicineSession $session): void
    {
        $session->loadMissing([
            'request.residentProfile.user',
            'assignedDoctor',
        ]);

        $resident = $session->request?->residentProfile?->user;
        $doctor = $session->assignedDoctor;

        if ($resident) {
            $this->notifyUser(
                $resident,
                NotificationTypes::TELE_SESSION_SCHEDULED,
                'Telemedicine session scheduled',
                'Your telemedicine session has been scheduled. Open Telemedicine for details.',
                [
                    'related_type' => 'telemedicine',
                    'related_id' => $session->id,
                    'session_id' => $session->id,
                ],
                '/telemedicine'
            );
        }

        if ($doctor) {
            $this->notifyUser(
                $doctor,
                NotificationTypes::TELE_SESSION_SCHEDULED,
                'Telemedicine session assigned',
                'A telemedicine session has been assigned to you.',
                [
                    'related_type' => 'telemedicine',
                    'related_id' => $session->id,
                    'session_id' => $session->id,
                ],
                '/telemedicine'
            );
        }
    }

    public function notifyTelemedicineSessionStatus(TelemedicineSession $session): void
    {
        $session->loadMissing([
            'request.residentProfile.user',
            'assignedDoctor',
        ]);

        $resident = $session->request?->residentProfile?->user;
        $doctor = $session->assignedDoctor;

        $status = (string) $session->status;

        $title = match ($status) {
            'waiting' => 'Telemedicine room is open',
            'active' => 'Telemedicine session started',
            'ended' => 'Telemedicine session ended',
            'no_show' => 'Telemedicine marked no-show',
            'cancelled' => 'Telemedicine cancelled',
            default => 'Telemedicine session updated',
        };

        $message = match ($status) {
            'waiting' => 'The telemedicine room is now open. Please join when ready.',
            'active' => 'Your telemedicine session has started.',
            'ended' => 'Your telemedicine session has ended.',
            'no_show' => 'The telemedicine session was marked as no-show.',
            'cancelled' => 'The telemedicine session was cancelled.',
            default => "Telemedicine session status is now {$status}.",
        };

        foreach ([$resident, $doctor] as $user) {
            if ($user) {
                $this->notifyUser(
                    $user,
                    $status === 'ended'
                        ? NotificationTypes::TELE_SESSION_ENDED
                        : NotificationTypes::TELE_SESSION_STARTED,
                    $title,
                    $message,
                    [
                        'related_type' => 'telemedicine',
                        'related_id' => $session->id,
                        'session_id' => $session->id,
                        'status' => $status,
                    ],
                    '/telemedicine'
                );
            }
        }
    }

    public function notifyQueueTicketIssued(QueueTicket $ticket): void
    {
        $ticket->loadMissing('residentProfile.user');

        $resident = $ticket->residentProfile?->user;

        if (!$resident) {
            return;
        }

        $ticketNumber = $ticket->ticket_number ?? $ticket->queue_number ?? $ticket->id;

        $this->notifyUser(
            $resident,
            NotificationTypes::QUEUE_TICKET_ISSUED,
            'Queue ticket issued',
            "Your queue ticket #{$ticketNumber} has been issued.",
            [
                'related_type' => 'queue',
                'related_id' => $ticket->id,
                'ticket_number' => $ticketNumber,
            ],
            '/queue'
        );
    }

    public function notifyQueueTicketCalled(QueueTicket $ticket): void
    {
        $ticket->loadMissing('residentProfile.user');

        $resident = $ticket->residentProfile?->user;

        if (!$resident) {
            return;
        }

        $ticketNumber = $ticket->ticket_number ?? $ticket->queue_number ?? $ticket->id;

        $this->notifyUser(
            $resident,
            NotificationTypes::QUEUE_TICKET_CALLED,
            'Your queue number is being called',
            "Ticket #{$ticketNumber} is now being called. Please proceed to the waiting area.",
            [
                'related_type' => 'queue',
                'related_id' => $ticket->id,
                'ticket_number' => $ticketNumber,
            ],
            '/queue'
        );
    }

    public function notifyEventPublished(Event $event): void
    {
        $type = $event->event_type === 'program'
            ? NotificationTypes::PROGRAM_PUBLISHED
            : NotificationTypes::EVENT_PUBLISHED;

        $title = $event->event_type === 'program'
            ? 'New RHU program'
            : 'New RHU event';

        $this->notifyResidents(
            $type,
            $title,
            $event->title,
            [
                'related_type' => 'event',
                'related_id' => $event->id,
                'event_type' => $event->event_type,
                'category' => $event->category,
            ],
            "/events/{$event->id}"
        );
    }

    public function notifyAnnouncementPublished(Announcement $announcement): void
    {
        $this->notifyResidents(
            NotificationTypes::ANNOUNCEMENT_PUBLISHED,
            'New RHU announcement',
            $announcement->title,
            [
                'related_type' => 'announcement',
                'related_id' => $announcement->id,
                'category' => $announcement->category ?? 'general',
            ],
            '/announcements'
        );
    }

    private function userKey(?User $user): ?int
    {
        if (!$user) {
            return null;
        }

        return (int) ($user->getKey() ?: ($user->user_id ?? $user->id));
    }

    private function userName(?User $user): string
    {
        if (!$user) {
            return 'A resident';
        }

        $name = trim(implode(' ', array_filter([
            $user->first_name ?? null,
            $user->last_name ?? null,
        ])));

        return $name
            ?: ($user->full_name ?? null)
            ?: ($user->name ?? null)
            ?: 'A resident';
    }

    private function allowsInApp(int $userId, string $notificationType): bool
    {
        if (!Schema::hasTable('notification_preferences')) {
            return true;
        }

        $pref = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->where('notification_type', $notificationType)
            ->first();

        if (!$pref) {
            return true;
        }

        return (bool) ($pref->in_app ?? true);
    }

    private function resolveRoleIds(array $roles): array
    {
        if (!Schema::hasTable('user_roles')) {
            return [];
        }

        $key = Schema::hasColumn('user_roles', 'role_id') ? 'role_id' : 'id';

        $columns = ['name', 'slug', 'role', 'title', 'code', 'role_name'];

        $query = DB::table('user_roles')->select($key);

        $query->where(function ($q) use ($roles, $columns) {
            foreach ($columns as $column) {
                if (!Schema::hasColumn('user_roles', $column)) {
                    continue;
                }

                foreach ($roles as $role) {
                    $normalized = strtolower(str_replace([' ', '-'], '_', trim($role)));
                    $q->orWhereRaw("LOWER(REPLACE({$column}, ' ', '_')) = ?", [$normalized]);
                }
            }
        });

        return $query
            ->pluck($key)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}