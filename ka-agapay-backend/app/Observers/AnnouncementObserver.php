<?php
// app/Observers/AnnouncementObserver.php

namespace App\Observers;

use App\Models\Announcement;
use App\Services\Notification\NotificationService;

class AnnouncementObserver
{
    public function created(Announcement $announcement): void
    {
        if ($announcement->status === 'published') {
            app(NotificationService::class)
                ->notifyAnnouncementPublished($announcement);
        }
    }

    public function updated(Announcement $announcement): void
    {
        $becamePublished =
            $announcement->wasChanged('status') &&
            $announcement->status === 'published';

        $publishedAtChanged =
            $announcement->wasChanged('published_at') &&
            $announcement->status === 'published';

        if ($becamePublished || $publishedAtChanged) {
            app(NotificationService::class)
                ->notifyAnnouncementPublished($announcement);
        }
    }
}