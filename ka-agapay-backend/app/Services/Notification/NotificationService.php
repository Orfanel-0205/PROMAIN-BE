<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Models\QueueTicket;
use App\Models\TelemedicineSession;
use App\Models\TelemedicineRequest;
use App\Notifications\QueueTicketCalledNotification;
use App\Notifications\TelemedicineSessionScheduledNotification;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    public function notifyQueueTicketCalled(QueueTicket $ticket): void
    {
        $resident = $ticket->residentProfile?->user;
        if ($resident) {
            $resident->notify(new QueueTicketCalledNotification($ticket));
        }
    }

    public function notifySessionScheduled(TelemedicineSession $session): void
    {
        $resident = $session->request->residentProfile?->user;
        $doctor   = $session->assignedDoctor;

        if ($resident) {
            $resident->notify(new TelemedicineSessionScheduledNotification($session));
        }

        if ($doctor) {
            $doctor->notify(new TelemedicineSessionScheduledNotification($session));
        }
    }

    // Called by scheduler — e.g., 30 minutes before session
    public function sendSessionReminders(): void
    {
        $upcoming = \App\Models\TelemedicineSession::where('status', 'scheduled')
            ->where('scheduled_date', today())
            ->whereRaw("scheduled_time::time BETWEEN (NOW() + interval '25 minutes')::time AND (NOW() + interval '35 minutes')::time")
            ->with(['request.residentProfile.user', 'assignedDoctor'])
            ->get();

        foreach ($upcoming as $session) {
            if ($resident = $session->request->residentProfile?->user) {
                // Ensure TelemedicineSessionReminderNotification class exists before instantiating
                if (class_exists(\App\Notifications\TelemedicineSessionReminderNotification::class)) {
                    $resident->notify(new \App\Notifications\TelemedicineSessionReminderNotification($session));
                }
            }
        }
    }
}
