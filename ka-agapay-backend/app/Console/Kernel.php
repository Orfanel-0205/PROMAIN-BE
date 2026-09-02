<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        // DISABLED 2026-09-02 -- matches the emergency hand-disable applied
        // directly on the production droplet. Do not re-enable as-is.
        //
        // This closure calls NotificationService::sendSessionReminders(), which
        // DOES NOT EXIST. It was deleted on 2026-06-17 in commit 4ec9675
        // ("feat: implement core modules for appointments, telemedicine...") and
        // was never reimplemented, so the job has thrown BadMethodCallException
        // on every run since. That stayed invisible because Laravel's
        // schedule:run swallows individual job failures and still exits 0, and
        // from 2026-07-05 the whole schedule aborted earlier anyway on the
        // withoutOverlapping() ordering bug (fixed in dd68d3d).
        //
        // With the schedule building again, this would fail every five minutes
        // -- 288 errors a day into the newly wired log/alert stack -- while
        // delivering nothing it has not already failed to deliver since June.
        //
        // BEFORE RE-ENABLING: implement NotificationService::sendSessionReminders().
        // That is real notification-pipeline work, not a rename -- it needs a
        // decision on the reminder window (how long before scheduled_at), how
        // repeat sends are deduped, and which channel is used (Expo push, SMS,
        // or both). Reinstate the three lines below only once it exists, and
        // confirm with `php artisan schedule:run -v`.
        //
        // $schedule->call(function () {
        //     app(\App\Services\Notification\NotificationService::class)->sendSessionReminders();
        // })->everyFiveMinutes()->description('Send telemedicine session reminders');

        $schedule->call(function () {
            $count = app(\App\Services\Prescription\PrescriptionService::class)->expireStale();
            logger()->info("Expired {$count} stale prescriptions.");
        })->name('Expire stale prescriptions')
            ->dailyAt('00:05')
            ->onFailure(fn () => $this->reportScheduleFailure('Expire stale prescriptions'));

        $schedule->command('followups:send-reminders')
            ->name('Send follow-up reminder push notifications')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->onFailure(fn (Stringable $output) => $this->reportScheduleFailure(
                'Send follow-up reminder push notifications',
                (string) $output
            ));

        // 3-days-before SMS reminder for published events, scoped to each
        // event's barangay/facility audience. Idempotent (reminder_sms_sent_at).
        $schedule->command('events:send-reminders')
            ->name('Send 3-days-before event SMS reminders to target audiences')
            ->dailyAt('08:15')
            ->withoutOverlapping()
            ->onFailure(fn (Stringable $output) => $this->reportScheduleFailure(
                'Send 3-days-before event SMS reminders to target audiences',
                (string) $output
            ));

        // Part 2 (trigger #4) — daily staff alerts for low/out/expiring inventory
        // so alerts are not limited to items that had a stock movement. Deduped.
        //
        // ORDERING MATTERS on closure events: CallbackEvent::withoutOverlapping()
        // throws unless the name is already set, because the overlap mutex key is
        // sha1() of that name and a closure has nothing else to derive it from.
        // This line previously called ->description() AFTER ->withoutOverlapping(),
        // which threw a LogicException while the schedule was being BUILT -- so
        // every `schedule:run` aborted and NONE of the jobs here ever executed.
        // Keep the name before withoutOverlapping().
        $schedule->call(function () {
            $count = app(\App\Services\Notification\NotificationService::class)->sweepInventoryAlerts();
            logger()->info("Swept {$count} inventory item(s) for staff stock/expiry alerts.");
        })->name('Sweep inventory low-stock / expiry alerts')
            ->dailyAt('07:30')
            ->withoutOverlapping()
            ->onFailure(fn () => $this->reportScheduleFailure('Sweep inventory low-stock / expiry alerts'));
    }

    /**
     * Announce a failed scheduled job at error level.
     *
     * Why this exists: `schedule:run` exits 0 even when a job fails, so a dead
     * job is invisible unless something writes it down. What gets written down
     * differs by event type, and only one of the two was actually covered:
     *
     *   - CLOSURE jobs already surfaced. CallbackEvent::run() rethrows, and
     *     ScheduleRunCommand::runEvent() catches it and calls handler->report(),
     *     which logs at error. Verified against the real
     *     NotificationService::sendSessionReminders() failure, which did land in
     *     the log as "local.ERROR: Call to undefined method ...".
     *
     *   - COMMAND jobs did NOT. They run as a subprocess; a non-zero exit code
     *     produces no exception, so nothing was reported and nothing was logged.
     *     That was the real gap, and it is the reason both ->command() jobs here
     *     take the Stringable $output overload: it is the only way to recover
     *     what the failing command actually said.
     *
     * Naming the parameter $output and typing it Stringable is load-bearing --
     * Event::onFailure() inspects exactly that to upgrade the callback to
     * onFailureWithOutput(). Rename it and the output silently goes missing.
     *
     * Error level is deliberate: it clears the LOG_SLACK_LEVEL=warning threshold
     * so these reach the alert channel, not just the daily log file.
     */
    private function reportScheduleFailure(string $job, ?string $output = null): void
    {
        $detail = $output !== null ? trim($output) : null;

        Log::error("[schedule] Scheduled job FAILED: {$job}", array_filter([
            'job'    => $job,
            'detail' => $detail !== '' ? $detail : null,
        ], fn ($value) => $value !== null));
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
