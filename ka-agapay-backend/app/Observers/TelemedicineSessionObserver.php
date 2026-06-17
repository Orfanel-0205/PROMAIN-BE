<?php
// app/Observers/TelemedicineSessionObserver.php

namespace App\Observers;

use App\Models\TelemedicineSession;
use App\Services\Notification\NotificationService;

class TelemedicineSessionObserver
{
    public function created(TelemedicineSession $session): void
    {
        app(NotificationService::class)
            ->notifySessionScheduled($session);
    }

    public function updated(TelemedicineSession $session): void
    {
        if (!$session->wasChanged('status')) {
            return;
        }

        app(NotificationService::class)
            ->notifyTelemedicineSessionStatus($session);
    }
}