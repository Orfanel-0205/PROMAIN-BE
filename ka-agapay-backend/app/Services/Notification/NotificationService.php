<?php
// app/Services/Notification/NotificationService.php

namespace App\Services\Notification;

use App\Models\Announcement;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\FollowUpReminder;
use App\Models\InventoryItem;
use App\Models\QueueTicket;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\User;
use App\Models\UserDeviceToken;
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
        ?string $actionUrl = null,
        ?string $pushChannelId = null
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

        $residents = $query->get();

        $this->notifyUsers(
            $residents,
            $notificationType,
            $title,
            $message,
            $meta,
            $actionUrl
        );

        // PART 2 (trigger #1) — fan-out an Expo push so residents learn about a
        // new event/announcement without opening the app. Every recipient already
        // has the in-app row created above, so the Notifications screen and the
        // push stay consistent (never push-only, never list-only).
        if ($pushChannelId !== null) {
            $this->pushToUsersWithTokens(
                $residents,
                $title,
                $message,
                array_merge($meta, [
                    'type' => $notificationType,
                    'notification_type' => $notificationType,
                    'action_url' => $actionUrl,
                ]),
                $pushChannelId
            );
        }
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

        $notifType = match ($status) {
            'confirmed', 'approved', 'scheduled' => NotificationTypes::APPOINTMENT_CONFIRMED,
            'cancelled'                           => NotificationTypes::APPOINTMENT_CANCELLED,
            'rejected'                            => NotificationTypes::APPOINTMENT_REJECTED,
            'completed'                           => NotificationTypes::APPOINTMENT_COMPLETED,
            default                               => NotificationTypes::APPOINTMENT_UPDATED,
        };

        $title = match ($status) {
            'confirmed', 'approved', 'scheduled' => 'Appointment approved',
            'cancelled'                           => 'Appointment cancelled',
            'rejected'                            => 'Appointment rejected',
            'completed'                           => 'Appointment completed',
            default                               => 'Appointment updated',
        };

        $message = match ($status) {
            'confirmed', 'approved', 'scheduled' => 'Your RHU appointment has been approved. Please check your schedule.',
            'cancelled'                           => 'Your RHU appointment has been cancelled.',
            'rejected'                            => 'Your RHU appointment request was rejected. Please check the reason or submit another request.',
            'completed'                           => 'Your RHU appointment has been marked completed.',
            default                               => "Your appointment status is now {$status}.",
        };

        // Payload includes routing fields so the mobile push handler
        // can navigate directly to the appointment detail screen on tap.
        $payload = [
            'type'           => $notifType,
            'screen'         => 'appointments',
            'appointment_id' => $appointment->id,
            'related_type'   => 'appointment',
            'related_id'     => $appointment->id,
            'status'         => $status,
            'rhu_id'         => $appointment->rhu_id,
        ];

        $this->notifyUser(
            $resident,
            $notifType,
            $title,
            $message,
            $payload,
            '/appointments'
        );

        // Send push notification to the resident's device (non-blocking).
        // Mirrors the same pattern used by notifyQueueTicketCalled().
        try {
            $userId = $this->userKey($resident);

            if ($userId && Schema::hasTable('user_device_tokens')) {
                $tokenCount = (int) UserDeviceToken::query()
                    ->where('user_id', $userId)
                    ->where('provider', 'expo')
                    ->where('is_active', true)
                    ->count();

                if ($tokenCount > 0) {
                    app(ExpoPushService::class)->sendToUser(
                        userId: $userId,
                        title: $title,
                        body: $message,
                        data: $payload,
                        channelId: 'default'
                    );
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('[NotificationService] Appointment push notification failed.', [
                'appointment_id' => $appointment->id ?? null,
                'status'         => $status,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    public function notifyTelemedicineEndorsed(TelemedicineRequest $request): array
    {
        $request->loadMissing(['residentProfile.user', 'rhu', 'endorsedTo']);

        $endorsedTo = $request->endorsedTo;

        if (!$endorsedTo) {
            return ['notified' => false, 'message' => 'No doctor assigned for endorsement.'];
        }

        $patientName = $this->userName($request->residentProfile?->user);
        $rhuName = $request->rhu?->name ?? 'RHU';

        $title = 'Telemedicine Request Endorsed to You';
        $message = "A telemedicine request from {$patientName} at {$rhuName} has been endorsed to you for scheduling.";

        $payload = [
            'type'         => NotificationTypes::TELE_REQUEST_ENDORSED,
            'screen'       => 'telemedicine',
            'request_id'   => $request->id,
            'related_type' => 'telemedicine_request',
            'related_id'   => $request->id,
            'patient_name' => $patientName,
            'rhu_id'       => $request->rhu_id,
        ];

        $databaseCreated = (bool) $this->notifyUser(
            $endorsedTo,
            NotificationTypes::TELE_REQUEST_ENDORSED,
            $title,
            $message,
            $payload,
            '/telemedicine'
        );

        $pushSent = false;

        try {
            $endorsedId = $this->userKey($endorsedTo);

            if ($endorsedId && Schema::hasTable('user_device_tokens')) {
                $tokenCount = (int) UserDeviceToken::query()
                    ->where('user_id', $endorsedId)
                    ->where('provider', 'expo')
                    ->where('is_active', true)
                    ->count();

                if ($tokenCount > 0) {
                    app(ExpoPushService::class)->sendToUser(
                        userId: $endorsedId,
                        title: $title,
                        body: $message,
                        data: $payload,
                        channelId: 'default'
                    );

                    $pushSent = true;
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('[NotificationService] Telemedicine endorsement push failed.', [
                'request_id' => $request->id,
                'endorsed_to' => $request->endorsed_to,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'notified'         => $databaseCreated,
            'push_sent'        => $pushSent,
            'database_created' => $databaseCreated,
        ];
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
        $isCallingStatus = in_array($status, ['waiting', 'calling', 'live', 'active', 'started'], true);

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

        if ($isCallingStatus && $resident) {
            $this->notifyTelemedicineCalling($session);
        }

        $recipients = $isCallingStatus ? [$doctor] : [$resident, $doctor];

        foreach ($recipients as $user) {
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

    public function notifyTelemedicineCalling(TelemedicineSession $session): array
    {
        $result = [
            'database_created' => false,
            'push_configured' => Schema::hasTable('user_device_tokens'),
            'push_tokens' => 0,
            'push_sent' => false,
            'sound' => 'default',
            'message' => 'Telemedicine calling notification was not sent.',
        ];

        try {
            $session->loadMissing([
                'request.residentProfile.user',
                'request.rhu',
            ]);

            $resident = $session->request?->residentProfile?->user;

            if (!$resident) {
                $result['message'] = 'Telemedicine session has no linked resident account.';

                return $result;
            }

            $request = $session->request;
            $title = 'Telemedicine session is ready';
            $message = 'Your RHU telemedicine consultation is ready. Tap to join.';
            $payload = [
                'type' => 'telemedicine_calling',
                'screen' => 'telemedicine',
                'telemedicine_session_id' => $session->id,
                'session_id' => $session->id,
                'telemedicine_request_id' => $request?->id,
                'request_id' => $request?->id,
                'rhu_id' => $request?->rhu_id,
                'status' => $session->status,
                'related_type' => 'telemedicine',
                'related_id' => $session->id,
            ];

            $notificationId = $this->notifyUser(
                $resident,
                NotificationTypes::TELEMEDICINE_CALLING,
                $title,
                $message,
                $payload,
                '/telemedicine'
            );

            $result['database_created'] = (bool) $notificationId;

            $userId = $this->userKey($resident);

            if ($userId && Schema::hasTable('user_device_tokens')) {
                $result['push_tokens'] = (int) UserDeviceToken::query()
                    ->where('user_id', $userId)
                    ->where('provider', 'expo')
                    ->where('is_active', true)
                    ->count();

                if ($result['push_tokens'] > 0) {
                    $sent = app(ExpoPushService::class)->sendToUser(
                        userId: $userId,
                        title: $title,
                        body: $message,
                        data: $payload,
                        channelId: 'telemedicine-calls'
                    );

                    $result['push_sent'] = $sent > 0;
                }
            }

            $result['message'] = $result['push_sent']
                ? 'Telemedicine calling push sent to patient.'
                : ($result['database_created']
                    ? 'Telemedicine calling in-app notification was created, but no active mobile push token was available.'
                    : 'Telemedicine calling notification could not be stored.');

            return $result;
        } catch (\Throwable $e) {
            logger()->warning('[NotificationService] Telemedicine calling notification failed.', [
                'telemedicine_session_id' => $session->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return $result;
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

    public function notifyQueueTicketCalled(QueueTicket $ticket): array
    {
        $result = [
            'database_created' => false,
            'push_configured' => Schema::hasTable('user_device_tokens'),
            'push_tokens' => 0,
            'push_sent' => false,
            'sound' => 'default',
            'message' => 'Queue called, but mobile notification service is not configured.',
        ];

        try {
            $ticket->loadMissing('residentProfile.user');

            $resident = $ticket->residentProfile?->user;

            if (!$resident) {
                $result['message'] = 'Queue called, but no linked resident account was found.';

                return $result;
            }

            $ticketNumber = $ticket->ticket_number ?? $ticket->queue_number ?? $ticket->id;
            $deskName = $this->serviceLabel((string) $ticket->service_type);
            $patientName = $this->userName($resident);
            $title = 'Your queue number is being called';
            $message = "Queue {$ticketNumber} is now being called. Please proceed to {$deskName}.";
            $payload = [
                'type' => 'queue_called',
                'queue_ticket_id' => $ticket->id,
                'queue_number' => $ticketNumber,
                'ticket_number' => $ticketNumber,
                'patient_name' => $patientName,
                'desk' => $deskName,
                'rhu_id' => $ticket->rhu_id,
                'status' => $ticket->status,
                'screen' => 'queue',
                'related_type' => 'queue',
                'related_id' => $ticket->id,
            ];

            $notificationId = $this->notifyUser(
                $resident,
                NotificationTypes::QUEUE_TICKET_CALLED,
                $title,
                $message,
                $payload,
                '/queue'
            );

            $result['database_created'] = (bool) $notificationId;

            $userId = $this->userKey($resident);

            if ($userId && Schema::hasTable('user_device_tokens')) {
                $result['push_tokens'] = (int) UserDeviceToken::query()
                    ->where('user_id', $userId)
                    ->where('provider', 'expo')
                    ->where('is_active', true)
                    ->count();

                if ($result['push_tokens'] > 0) {
                    $sent = app(ExpoPushService::class)->sendToUser(
                        userId: $userId,
                        title: $title,
                        body: $message,
                        data: $payload,
                        channelId: 'queue-alerts'
                    );

                    $result['push_sent'] = $sent > 0;
                }
            }

            $result['message'] = $result['push_sent']
                ? 'Notification sent to patient.'
                : ($result['database_created']
                    ? 'Queue called; in-app notification was created, but no active mobile push token was available.'
                    : 'Queue called, but the resident has disabled in-app notifications or notification storage failed.');

            return $result;
        } catch (\Throwable $e) {
            logger()->warning('[NotificationService] Queue called notification failed.', [
                'queue_ticket_id' => $ticket->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    public function notifyFollowUpReminder(FollowUpReminder $reminder, string $stage): array
    {
        $result = [
            'database_created' => false,
            'duplicate' => false,
            'push_configured' => Schema::hasTable('user_device_tokens'),
            'push_tokens' => 0,
            'push_sent' => false,
            'sound' => 'default',
            'message' => 'Follow-up reminder notification was not sent.',
        ];

        try {
            $reminder->loadMissing(['user', 'consultation', 'appointment']);

            $resident = $reminder->user;

            if (!$resident) {
                $result['message'] = 'Follow-up reminder has no linked resident account.';

                return $result;
            }

            $userId = $this->userKey($resident);

            if (!$userId) {
                $result['message'] = 'Follow-up reminder resident account has no usable user id.';

                return $result;
            }

            $followUpDate = $reminder->follow_up_date?->toDateString()
                ?? $reminder->follow_up_at?->toDateString()
                ?? now()->toDateString();
            $stage = $stage === 'day_of' ? 'day_of' : 'three_days_before';
            $dedupeKey = "followup_reminder:{$reminder->id}:{$stage}:{$followUpDate}";

            if ($this->notificationDedupeExists($userId, $dedupeKey)) {
                $result['duplicate'] = true;
                $result['message'] = 'Follow-up reminder already sent for this stage.';

                return $result;
            }

            $title = $stage === 'day_of'
                ? 'RHU follow-up today'
                : 'Upcoming RHU follow-up';
            $message = $stage === 'day_of'
                ? 'Your RHU follow-up is scheduled today. Please check your consultation details.'
                : "Your RHU follow-up is scheduled on {$followUpDate}. Please check your consultation details.";
            $payload = [
                'type' => 'followup_reminder',
                'screen' => 'consultations',
                'consultation_id' => $reminder->consultation_id,
                'follow_up_id' => $reminder->id,
                'appointment_id' => $reminder->appointment_id,
                'reminder_stage' => $stage,
                'follow_up_date' => $followUpDate,
                'dedupe_key' => $dedupeKey,
                'related_type' => 'follow_up',
                'related_id' => $reminder->id,
            ];

            $notificationId = $this->notifyUser(
                $resident,
                NotificationTypes::FOLLOWUP_REMINDER,
                $title,
                $message,
                $payload,
                '/consultations'
            );

            $result['database_created'] = (bool) $notificationId;

            if (!$notificationId && !$this->notificationDedupeExists($userId, $dedupeKey)) {
                $notificationId = $this->storeNotificationDedupeRow(
                    $userId,
                    NotificationTypes::FOLLOWUP_REMINDER,
                    $title,
                    $message,
                    $payload,
                    '/consultations'
                );

                $result['database_created'] = (bool) $notificationId;
            }

            if (Schema::hasTable('user_device_tokens')) {
                $result['push_tokens'] = (int) UserDeviceToken::query()
                    ->where('user_id', $userId)
                    ->where('provider', 'expo')
                    ->where('is_active', true)
                    ->count();

                if ($result['push_tokens'] > 0) {
                    $sent = app(ExpoPushService::class)->sendToUser(
                        userId: $userId,
                        title: $title,
                        body: $message,
                        data: $payload,
                        channelId: 'follow-up-reminders'
                    );

                    $result['push_sent'] = $sent > 0;
                }
            }

            $result['message'] = $result['push_sent']
                ? 'Follow-up reminder push sent to patient.'
                : ($result['database_created']
                    ? 'Follow-up reminder row was created, but no active mobile push token was available.'
                    : 'Follow-up reminder could not be stored.');

            return $result;
        } catch (\Throwable $e) {
            logger()->warning('[NotificationService] Follow-up reminder notification failed.', [
                'follow_up_id' => $reminder->id ?? null,
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
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
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'category' => $event->category,
                'screen' => 'home',
            ],
            "/events/{$event->id}",
            'default'
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
                'screen' => 'home',
            ],
            '/announcements',
            'default'
        );
    }

    /**
     * PART 2 — push a single title/body/data to every given user that has an
     * active Expo token. Pre-filters to token holders so a large resident list
     * does not trigger one HTTP call per tokenless account. Never throws.
     */
    private function pushToUsersWithTokens(
        $users,
        string $title,
        string $body,
        array $data,
        string $channelId
    ): void {
        if (!Schema::hasTable('user_device_tokens')) {
            return;
        }

        $userIds = collect($users)
            ->map(fn ($user) => $this->userKey($user))
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $targetIds = UserDeviceToken::query()
            ->whereIn('user_id', $userIds->all())
            ->where('provider', 'expo')
            ->where('is_active', true)
            ->pluck('user_id')
            ->unique();

        if ($targetIds->isEmpty()) {
            return;
        }

        $expo = app(ExpoPushService::class);

        foreach ($targetIds as $uid) {
            try {
                $expo->sendToUser((int) $uid, $title, $body, $data, $channelId);
            } catch (\Throwable $e) {
                logger()->warning('[NotificationService] Bulk resident push failed.', [
                    'user_id' => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * PART 2 (trigger #4) — STAFF-ONLY inventory alert. Creates an in-app
     * notification-center entry for RHU staff (via notifyAdmins, which never
     * pushes to resident devices). Deduped per item + kind + day so repeated
     * stock movements or a re-run of the daily sweep do not spam.
     */
    public function notifyInventoryStockAlert(InventoryItem $item): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        $item = $item->fresh() ?? $item;

        $stock = (int) ($item->current_stock ?? 0);
        $min   = (int) ($item->minimum_stock_level ?? 0);
        $name  = (string) ($item->name ?? ('Item #' . $item->id));
        $unit  = (string) ($item->unit_of_measure ?? 'units');

        // 1) Stock level — out of stock or below reorder point.
        if ($stock <= 0) {
            $this->dispatchInventoryAlert(
                $item,
                'out_of_stock',
                'critical',
                'Medicine out of stock',
                "{$name} is out of stock. Reorder now to avoid service disruption."
            );
        } elseif ($min > 0 && $stock <= $min) {
            $this->dispatchInventoryAlert(
                $item,
                'low_stock',
                'warning',
                'Low stock alert',
                "{$name} is low: {$stock} {$unit} left (reorder point {$min})."
            );
        }

        // 2) Expiry — already expired or expiring within 30 days.
        $expiration = $item->expiration_date ?? null;

        if ($expiration) {
            try {
                $expDate = $expiration instanceof \DateTimeInterface
                    ? \Illuminate\Support\Carbon::instance($expiration)
                    : \Illuminate\Support\Carbon::parse((string) $expiration);

                $days = now()->startOfDay()->diffInDays($expDate->startOfDay(), false);

                if ($days < 0) {
                    $this->dispatchInventoryAlert(
                        $item,
                        'expired',
                        'critical',
                        'Expired medicine',
                        "{$name} expired on {$expDate->toDateString()}. Remove it from usable stock."
                    );
                } elseif ($days <= 30) {
                    $this->dispatchInventoryAlert(
                        $item,
                        'expiring',
                        'warning',
                        'Medicine expiring soon',
                        "{$name} expires in {$days} day(s) on {$expDate->toDateString()}. Use or replace it soon."
                    );
                }
            } catch (\Throwable) {
                // Unparseable expiry date — ignore.
            }
        }
    }

    private function dispatchInventoryAlert(
        InventoryItem $item,
        string $kind,
        string $severity,
        string $title,
        string $message
    ): void {
        $dedupeKey = "inventory_alert:{$item->id}:{$kind}:" . now()->toDateString();

        if ($this->inventoryAlertDedupeExists($dedupeKey)) {
            return;
        }

        // Staff-facing only. notifyAdmins() writes notification rows for staff and
        // does NOT send any push — inventory alerts must never reach residents.
        $this->notifyAdmins(
            NotificationTypes::INVENTORY_LOW_STOCK,
            $title,
            $message,
            [
                'related_type'  => 'inventory',
                'related_id'    => $item->id,
                'item_code'     => $item->item_code ?? null,
                'current_stock' => (int) ($item->current_stock ?? 0),
                'reorder_point' => (int) ($item->minimum_stock_level ?? 0),
                'alert_kind'    => $kind,
                'severity'      => $severity,
                'screen'        => 'inventory',
                'dedupe_key'    => $dedupeKey,
            ],
            '/inventory'
        );
    }

    private function inventoryAlertDedupeExists(string $dedupeKey): bool
    {
        if (!Schema::hasTable('notifications')) {
            return false;
        }

        $query = DB::table('notifications')
            ->where('type', NotificationTypes::INVENTORY_LOW_STOCK);

        $this->whereNotificationDataContains($query, $dedupeKey);

        return $query->exists();
    }

    /**
     * Daily scheduled sweep so alerts are not limited to items that happened to
     * have a stock movement. Deduped, so it is safe to run repeatedly.
     */
    public function sweepInventoryAlerts(): int
    {
        if (!Schema::hasTable('inventory_items')) {
            return 0;
        }

        $count = 0;

        InventoryItem::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereColumn('current_stock', '<=', 'minimum_stock_level')
                    ->orWhere('current_stock', '<=', 0)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('expiration_date')
                            ->where('expiration_date', '<=', now()->addDays(30));
                    });
            })
            ->chunkById(200, function ($items) use (&$count) {
                foreach ($items as $item) {
                    $this->notifyInventoryStockAlert($item);
                    $count++;
                }
            });

        return $count;
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

    private function serviceLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'opd_consultation' => 'OPD Consultation',
            'prenatal_checkup' => 'Prenatal Checkup',
            'immunization' => 'Immunization',
            'family_planning' => 'Family Planning',
            'tb_dots' => 'TB DOTS',
            'laboratory' => 'Laboratory',
            'dental' => 'Dental',
            'emergency' => 'Emergency',
            'medicine_release' => 'Medicine Release',
            'bhw_assisted' => 'BHW Assisted',
            default => ucwords(str_replace(['_', '-'], ' ', $serviceType)),
        };
    }

    private function notificationDedupeExists(int $userId, string $dedupeKey): bool
    {
        if (!Schema::hasTable('notifications')) {
            return false;
        }

        $query = DB::table('notifications')
            ->where('type', NotificationTypes::FOLLOWUP_REMINDER)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId);

        $this->whereNotificationDataContains($query, $dedupeKey);

        return $query->exists();
    }

    private function storeNotificationDedupeRow(
        int $userId,
        string $notificationType,
        string $title,
        string $message,
        array $meta,
        ?string $actionUrl = null
    ): ?string {
        if (!Schema::hasTable('notifications')) {
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

    private function whereNotificationDataContains($query, string $needle): void
    {
        $like = '%' . $needle . '%';
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->whereRaw('data::text LIKE ?', [$like]);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $query->whereRaw('CAST(data AS CHAR) LIKE ?', [$like]);

            return;
        }

        $query->where('data', 'like', $like);
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
