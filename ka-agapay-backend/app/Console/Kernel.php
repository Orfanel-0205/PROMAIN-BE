<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        $schedule->call(function () {
            app(\App\Services\Notification\NotificationService::class)->sendSessionReminders();
        })->everyFiveMinutes()->description('Send telemedicine session reminders');

        $schedule->call(function () {
            $count = app(\App\Services\Prescription\PrescriptionService::class)->expireStale();
            logger()->info("Expired {$count} stale prescriptions.");
        })->dailyAt('00:05')->description('Expire stale prescriptions');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
