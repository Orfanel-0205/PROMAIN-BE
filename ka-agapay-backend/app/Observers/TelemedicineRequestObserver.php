<?php
// app/Observers/TelemedicineRequestObserver.php

namespace App\Observers;

use App\Models\TelemedicineRequest;
use App\Services\Notification\NotificationService;

class TelemedicineRequestObserver
{
    public function created(TelemedicineRequest $request): void
    {
        app(NotificationService::class)
            ->notifyTelemedicineRequestReceived($request);
    }

    public function updated(TelemedicineRequest $request): void
    {
        if (!$request->wasChanged('status')) {
            return;
        }

        app(NotificationService::class)
            ->notifyTelemedicineRequestStatus($request);
    }
}