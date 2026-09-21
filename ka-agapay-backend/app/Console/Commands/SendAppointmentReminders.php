<?php
// app/Console/Commands/SendAppointmentReminders.php
//
// Reminds patients that they have an appointment.
//
// Events and follow-ups have had reminder jobs for months. Appointments --
// the thing most patients actually book -- had none. Somebody booked, got one
// approval message, and then heard nothing until the day. That is how a
// booking system quietly becomes a no-show problem that looks like patients
// being unreliable, and it is measurable: "booked but did not arrive" on the
// attendance report is exactly this number.
//
// Two runs, matching how people remember things: the evening before, while
// there is still time to rearrange, and the morning of, so it is not
// forgotten on the day.

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders
                            {--stage=both : day_before, day_of, or both}
                            {--date= : Anchor date in YYYY-MM-DD. Defaults to today.}
                            {--dry-run : Report who would be reminded without sending anything}';

    protected $description = 'Remind patients about appointments happening tomorrow and today';

    /** Only appointments that are actually going ahead. */
    private const LIVE_STATUSES = ['confirmed', 'approved', 'scheduled'];

    public function handle(NotificationService $notifications): int
    {
        $anchor = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : today();

        $stage = (string) $this->option('stage');

        $targets = [];

        if ($stage === 'both' || $stage === 'day_of') {
            $targets['day_of'] = $anchor->copy();
        }

        if ($stage === 'both' || $stage === 'day_before') {
            $targets['day_before'] = $anchor->copy()->addDay();
        }

        $summary = ['checked' => 0, 'sent' => 0, 'duplicates' => 0, 'no_token' => 0, 'failed' => 0];

        foreach ($targets as $which => $date) {
            Appointment::query()
                ->whereDate('appointment_date', $date->toDateString())
                ->whereIn('status', self::LIVE_STATUSES)
                ->orderBy('id')
                ->chunkById(100, function ($appointments) use ($notifications, $which, &$summary) {
                    foreach ($appointments as $appointment) {
                        $summary['checked']++;

                        if ($this->option('dry-run')) {
                            $this->line(sprintf(
                                '  would remind appointment #%d (%s) - %s',
                                $appointment->id,
                                $appointment->appointment_date,
                                $which
                            ));

                            continue;
                        }

                        $result = $notifications->notifyAppointmentReminder($appointment, $which);

                        if ($result['duplicate'] ?? false) {
                            $summary['duplicates']++;
                        } elseif ($result['push_sent'] ?? false) {
                            $summary['sent']++;
                        } elseif (($result['push_tokens'] ?? 0) === 0) {
                            // Reached in the app but not on the lock screen: the
                            // patient has no registered device. Worth counting
                            // separately, because a high number here means the
                            // reminders are not doing what they are for.
                            $summary['no_token']++;
                        } else {
                            $summary['failed']++;
                        }
                    }
                });
        }

        $line = sprintf(
            'Appointment reminders — checked %d, pushed %d, already sent %d, no device %d, failed %d.',
            $summary['checked'],
            $summary['sent'],
            $summary['duplicates'],
            $summary['no_token'],
            $summary['failed']
        );

        $this->info($line);

        if (!$this->option('dry-run')) {
            Log::info('[appointments:send-reminders] ' . $line);
        }

        return self::SUCCESS;
    }
}
