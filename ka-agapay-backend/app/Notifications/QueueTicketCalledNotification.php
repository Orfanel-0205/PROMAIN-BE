<?php

namespace App\Notifications;

use App\Models\QueueTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class QueueTicketCalledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly QueueTicket $ticket) {}

    public function via(object $notifiable): array
    {
        // Check user's notification preferences
        $prefs = $notifiable->notificationPreferences()
            ->where('notification_type', NotificationTypes::QUEUE_TICKET_CALLED)
            ->first();

        $channels = ['database'];

        if ($prefs?->sms) {
            $channels[] = \App\Channels\SmsChannel::class; // custom channel
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'          => NotificationTypes::QUEUE_TICKET_CALLED,
            'title'         => 'Your Queue Number is Being Called',
            'body'          => "Ticket {$this->ticket->ticket_number} is now being called. Please proceed to the counter.",
            'ticket_number' => $this->ticket->ticket_number,
            'service_type'  => $this->ticket->service_type,
            'rhu_id'        => $this->ticket->rhu_id,
            'ticket_id'     => $this->ticket->id,
            'action_url'    => "/queue/{$this->ticket->id}",
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "Ka-agapay RHU: Ticket {$this->ticket->ticket_number} is now being called. Please proceed to the counter immediately. - RHU Malasiqui";
    }
}
