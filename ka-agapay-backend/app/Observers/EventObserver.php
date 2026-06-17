<?php
// app/Observers/EventObserver.php

namespace App\Observers;

use App\Models\Event;
use App\Services\Notification\NotificationService;

class EventObserver
{
    public function created(Event $event): void
    {
        if ((bool) $event->is_published) {
            app(NotificationService::class)
                ->notifyEventPublished($event);
        }
    }

    public function updated(Event $event): void
    {
        $becamePublished =
            $event->wasChanged('is_published') &&
            (bool) $event->is_published;

        $publishedAtChanged =
            $event->wasChanged('published_at') &&
            (bool) $event->is_published;

        if ($becamePublished || $publishedAtChanged) {
            app(NotificationService::class)
                ->notifyEventPublished($event);
        }
    }
}