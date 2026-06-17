<?php
// app/Observers/AppointmentObserver.php

namespace App\Observers;

use App\Models\Appointment;
use App\Services\Notification\NotificationService;

class AppointmentObserver
{
    public function created(Appointment $appointment): void
    {
        app(NotificationService::class)
            ->notifyAppointmentRequestReceived($appointment);
    }

    public function updated(Appointment $appointment): void
    {
        if (!$appointment->wasChanged('status')) {
            return;
        }

        app(NotificationService::class)
            ->notifyAppointmentStatus($appointment);
    }
}