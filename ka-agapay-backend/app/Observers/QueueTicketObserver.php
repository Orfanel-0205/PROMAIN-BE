<?php
// app/Observers/QueueTicketObserver.php

namespace App\Observers;

use App\Models\QueueTicket;
use App\Services\Notification\NotificationService;

class QueueTicketObserver
{
    public function created(QueueTicket $ticket): void
    {
        app(NotificationService::class)
            ->notifyQueueTicketIssued($ticket);
    }

    public function updated(QueueTicket $ticket): void
    {
        if (!$ticket->wasChanged('status')) {
            return;
        }

        if ($ticket->status === 'called') {
            app(NotificationService::class)
                ->notifyQueueTicketCalled($ticket);
        }
    }
}