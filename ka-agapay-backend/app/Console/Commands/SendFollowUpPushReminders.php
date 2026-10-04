<?php
// app/Console/Commands/SendFollowUpPushReminders.php

namespace App\Console\Commands;

use App\Models\FollowUpReminder;
use App\Services\Notification\FollowUpReminderSms;
use App\Services\Notification\NotificationService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Remind patients before a follow-up.
 *
 *   Three days before  text + app notification   time to arrange the trip
 *   The day before     text + app notification   so it is not forgotten
 *   The day itself     app notification only
 *
 * Runs every morning at 8:00 Philippine time (App\Console\Kernel), and counts
 * days in Philippine dates, so "tomorrow" means tomorrow where the patient is.
 * Only follow-ups that are still open -- pending or scheduled -- are reminded.
 * Each stage is sent once: app notifications are de-duplicated by
 * NotificationService, texts by FollowUpReminderSms.
 */
class SendFollowUpPushReminders extends Command
{
    protected $signature = 'followups:send-reminders
        {--date= : Anchor date in YYYY-MM-DD format. Defaults to today in the Philippines.}';

    protected $description = 'Remind patients of follow-ups three days before (SMS + app), the day before (SMS + app) and on the day (app).';

    private const OPEN_STATUSES = ['pending', 'scheduled'];

    public function handle(NotificationService $notifications, FollowUpReminderSms $texts): int
    {
        $anchorDate = $this->resolveAnchorDate();

        $summary = [
            'checked' => 0,
            'sent' => 0,
            'duplicates' => 0,
            'missing_token' => 0,
            'no_account' => 0,
            'failed' => 0,
            'sms_sent' => 0,
            'sms_failed' => 0,
            'sms_skipped' => 0,
        ];

        foreach (FollowUpReminder::REMINDER_STAGES as $stage => $daysBefore) {
            $date = $anchorDate->addDays($daysBefore)->toDateString();

            FollowUpReminder::query()
                ->with('user')
                ->whereIn('status', self::OPEN_STATUSES)
                // A range follow-up is reminded about its first day.
                ->where(function ($query) use ($date) {
                    $query->whereDate('follow_up_start_date', $date)
                        ->orWhere(function ($single) use ($date) {
                            $single->whereNull('follow_up_start_date')->whereDate('follow_up_date', $date);
                        });
                })
                ->orderBy('id')
                ->chunkById(100, function ($reminders) use ($notifications, $texts, $stage, &$summary) {
                    foreach ($reminders as $reminder) {
                        $summary['checked']++;

                        if (in_array($stage, FollowUpReminder::SMS_REMINDER_STAGES, true)) {
                            $outcome = $texts->send($reminder, $stage);

                            match ($outcome) {
                                'sent' => $summary['sms_sent']++,
                                'failed' => $summary['sms_failed']++,
                                default => $summary['sms_skipped']++,
                            };
                        }

                        // Most follow-ups belong to patients without an app
                        // account; for them the text above is the reminder.
                        if ($reminder->user_id === null) {
                            $summary['no_account']++;
                            continue;
                        }

                        $result = $notifications->notifyFollowUpReminder($reminder, $stage);

                        if ($result['duplicate'] ?? false) {
                            $summary['duplicates']++;
                            continue;
                        }

                        if ($result['push_sent'] ?? false) {
                            $summary['sent']++;
                            continue;
                        }

                        if (($result['push_tokens'] ?? 0) === 0 && ($result['database_created'] ?? false)) {
                            $summary['missing_token']++;
                            continue;
                        }

                        $summary['failed']++;
                    }
                });
        }

        Log::info('[FollowUpReminders] Send run completed.', array_merge([
            'anchor_date' => $anchorDate->toDateString(),
        ], $summary));

        $this->info(sprintf(
            'Follow-up reminders checked=%d push_sent=%d duplicates=%d missing_token=%d no_account=%d failed=%d sms_sent=%d sms_failed=%d sms_skipped=%d',
            $summary['checked'],
            $summary['sent'],
            $summary['duplicates'],
            $summary['missing_token'],
            $summary['no_account'],
            $summary['failed'],
            $summary['sms_sent'],
            $summary['sms_failed'],
            $summary['sms_skipped'],
        ));

        return self::SUCCESS;
    }

    private function resolveAnchorDate(): CarbonImmutable
    {
        $date = $this->option('date');

        if (is_string($date) && trim($date) !== '') {
            return CarbonImmutable::parse($date, LocalTime::zone())->startOfDay();
        }

        return LocalTime::today();
    }
}
