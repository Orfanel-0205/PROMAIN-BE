<?php

namespace App\Notifications;

use App\Models\TelemedicineSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class TelemedicineSessionScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly TelemedicineSession $session) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        $prefs = $notifiable->notificationPreferences()
            ->where('notification_type', NotificationTypes::TELE_SESSION_SCHEDULED)
            ->first();

        if ($prefs?->sms) {
            $channels[] = \App\Channels\SmsChannel::class;
        }
        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'       => NotificationTypes::TELE_SESSION_SCHEDULED,
            'title'      => 'Teleconsultation Scheduled',
            'body'       => "Your teleconsultation has been scheduled on {$this->session->scheduled_date->format('F d, Y')} at {$this->session->scheduled_time}.",
            'session_id' => $this->session->id,
            'doctor'     => optional($this->session->assignedDoctor)->first_name . ' ' . optional($this->session->assignedDoctor)->last_name,
            'date'       => $this->session->scheduled_date->toDateString(),
            'time'       => $this->session->scheduled_time,
            'mode'       => $this->session->session_mode,
            'action_url' => "/telemedicine/sessions/{$this->session->id}",
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "Ka-agapay: Your teleconsultation is set on {$this->session->scheduled_date->format('M d')} at {$this->session->scheduled_time}. Session mode: {$this->session->session_mode}. - RHU Malasiqui";
    }
}
