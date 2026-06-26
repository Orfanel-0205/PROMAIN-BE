<?php
// app/Console/Commands/SendFollowUpPushReminders.php

namespace App\Console\Commands;

use App\Models\FollowUpReminder;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendFollowUpPushReminders extends Command
{
    protected $signature = 'followups:send-reminders {--date= : Anchor date in YYYY-MM-DD format. Defaults to today.}';

    protected $description = 'Send Expo push and in-app reminders for follow-ups due in three days and today.';

    public function handle(NotificationService $notifications): int
    {
        $anchorDate = $this->resolveAnchorDate();

        $summary = [
            'checked' => 0,
            'sent' => 0,
            'duplicates' => 0,
            'missing_token' => 0,
            'failed' => 0,
        ];

        foreach ($this->targetStages($anchorDate) as $stage => $date) {
            FollowUpReminder::query()
                ->with('user')
                ->whereNotIn('status', ['completed', 'missed', 'cancelled'])
                ->where(function ($query) use ($date) {
                    $query
                        ->whereDate('follow_up_date', $date->toDateString())
                        ->orWhereDate('follow_up_at', $date->toDateString());
                })
                ->orderBy('id')
                ->chunkById(100, function ($reminders) use ($notifications, $stage, &$summary) {
                    foreach ($reminders as $reminder) {
                        $summary['checked']++;

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
            'Follow-up reminders checked=%d sent=%d duplicates=%d missing_token=%d failed=%d',
            $summary['checked'],
            $summary['sent'],
            $summary['duplicates'],
            $summary['missing_token'],
            $summary['failed']
        ));

        return self::SUCCESS;
    }

    private function resolveAnchorDate(): CarbonImmutable
    {
        $date = $this->option('date');

        if (is_string($date) && trim($date) !== '') {
            return CarbonImmutable::parse($date)->startOfDay();
        }

        return CarbonImmutable::today();
    }

    /**
     * @return array<string, CarbonImmutable>
     */
    private function targetStages(CarbonImmutable $anchorDate): array
    {
        return [
            'three_days_before' => $anchorDate->addDays(3),
            'day_of' => $anchorDate,
        ];
    }
}
