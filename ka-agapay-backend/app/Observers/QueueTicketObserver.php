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
        // Queue "called" notifications are sent from QueueService::transitionStatus()
        // so call-next endpoints can return database/push delivery metadata.
    }
}
