<?php
// app/Services/Notification/NotificationService.php

namespace App\Services\Notification;

use App\Models\QueueTicket;
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
    /**
     * Create one in-app database notification for a user.
     */
    public function notifyUser(
        User $user,
        string $notificationType,
        string $title,
        string $message,
        array $meta = [],
        ?string $actionUrl = null
    ): string {
        $userId = (int) ($user->user_id ?? $user->id);

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

    /**
     * Create the same notification for many users.
     */
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

    /**
     * Notify admin/staff roles when possible.
     */
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

        $query = User::query();

        if (Schema::hasColumn('users', 'role')) {
            $query->whereIn('role', [
                'admin',
                'staff',
                'rhu_admin',
                'super_admin',
                'mho',
                'doctor',
                'nurse',
                'midwife',
            ]);
        } elseif (Schema::hasColumn('users', 'role_name')) {
            $query->whereIn('role_name', [
                'admin',
                'staff',
                'rhu_admin',
                'super_admin',
                'mho',
                'doctor',
                'nurse',
                'midwife',
            ]);
        } elseif (Schema::hasColumn('users', 'user_type')) {
            $query->whereIn('user_type', [
                'admin',
                'staff',
                'rhu_admin',
                'super_admin',
                'mho',
                'doctor',
                'nurse',
                'midwife',
            ]);
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

    public function notifyQueueTicketCalled(QueueTicket $ticket): void
    {
        $resident = $ticket->residentProfile?->user;

        if (!$resident) {
            return;
        }

        $ticketNumber = $ticket->ticket_number ?? $ticket->queue_number ?? $ticket->id;

        $this->notifyUser(
            $resident,
            NotificationTypes::QUEUE_TICKET_CALLED,
            'Queue ticket called',
            "Your queue ticket #{$ticketNumber} has been called.",
            [
                'related_type' => 'queue',
                'related_id' => $ticket->id,
            ],
            '/queue'
        );
    }

    public function notifySessionScheduled(TelemedicineSession $session): void
    {
        $resident = $session->request->residentProfile?->user;
        $doctor = $session->assignedDoctor;

        if ($resident) {
            $this->notifyUser(
                $resident,
                NotificationTypes::TELE_SESSION_SCHEDULED,
                'Telemedicine session scheduled',
                'Your telemedicine session has been scheduled.',
                [
                    'related_type' => 'telemedicine',
                    'related_id' => $session->id,
                ],
                '/telemedicine'
            );
        }

        if ($doctor) {
            $this->notifyUser(
                $doctor,
                NotificationTypes::TELE_SESSION_SCHEDULED,
                'Telemedicine session scheduled',
                'A telemedicine session has been assigned to you.',
                [
                    'related_type' => 'telemedicine',
                    'related_id' => $session->id,
                ],
                '/telemedicine'
            );
        }
    }

    public function notifyAppointmentStatus(User $user, int|string $appointmentId, string $status): void
    {
        $title = match ($status) {
            'confirmed', 'approved', 'scheduled' => 'Appointment confirmed',
            'cancelled' => 'Appointment cancelled',
            'rejected' => 'Appointment rejected',
            'completed' => 'Appointment completed',
            default => 'Appointment updated',
        };

        $this->notifyUser(
            $user,
            NotificationTypes::APPOINTMENT_UPDATED,
            $title,
            "Your appointment status is now {$status}.",
            [
                'related_type' => 'appointment',
                'related_id' => $appointmentId,
                'status' => $status,
            ],
            '/appointments'
        );
    }

    public function notifyPrescriptionIssued(User $user, int|string $prescriptionId): void
    {
        $this->notifyUser(
            $user,
            NotificationTypes::PRESCRIPTION_ISSUED,
            'Prescription issued',
            'A new prescription has been added to your medical records.',
            [
                'related_type' => 'prescription',
                'related_id' => $prescriptionId,
            ],
            '/records'
        );
    }

    public function notifyEventPublished(int|string $eventId, string $title): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $users = User::query()
            ->when(Schema::hasColumn('users', 'account_status'), function ($q) {
                $q->where('account_status', 'active');
            })
            ->get();

        $this->notifyUsers(
            $users,
            NotificationTypes::EVENT_PUBLISHED,
            'New RHU event',
            $title,
            [
                'related_type' => 'event',
                'related_id' => $eventId,
            ],
            "/events/{$eventId}"
        );
    }

    public function notifyAnnouncementPublished(int|string $announcementId, string $title): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $users = User::query()
            ->when(Schema::hasColumn('users', 'account_status'), function ($q) {
                $q->where('account_status', 'active');
            })
            ->get();

        $this->notifyUsers(
            $users,
            NotificationTypes::ANNOUNCEMENT_PUBLISHED,
            'New RHU announcement',
            $title,
            [
                'related_type' => 'announcement',
                'related_id' => $announcementId,
            ],
            '/announcements'
        );
    }

    /**
     * Called by scheduler — e.g., 30 minutes before session.
     */
    public function sendSessionReminders(): void
    {
        if (!Schema::hasTable('telemedicine_sessions')) {
            return;
        }

        $upcoming = TelemedicineSession::where('status', 'scheduled')
            ->where('scheduled_date', today())
            ->with(['request.residentProfile.user', 'assignedDoctor'])
            ->get();

        foreach ($upcoming as $session) {
            $resident = $session->request->residentProfile?->user;

            if ($resident) {
                $this->notifyUser(
                    $resident,
                    NotificationTypes::TELE_SESSION_REMINDER,
                    'Telemedicine reminder',
                    'Your telemedicine session is coming up soon.',
                    [
                        'related_type' => 'telemedicine',
                        'related_id' => $session->id,
                    ],
                    '/telemedicine'
                );
            }
        }
    }
}