<?php
// app/Console/Commands/SendEventReminders.php
//
// Texts the audience of every published event/program whose start date is
// 3 DAYS from today and whose reminder has not been sent yet. Scheduled
// daily in Console\Kernel (same scheduler that already runs
// followups:send-reminders in production). Idempotent via
// events.reminder_sms_sent_at — safe to re-run any number of times.
//
//   php artisan events:send-reminders          # normal run
//   php artisan events:send-reminders --dry    # list matches, send nothing

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Notification\EventSmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders {--dry : List matching events without sending SMS}';

    protected $description = 'Send the 3-days-before SMS reminder for published events to their target audience';

    public function handle(EventSmsService $sms): int
    {
        if (!Schema::hasColumn('events', 'reminder_sms_sent_at')) {
            $this->error('events.reminder_sms_sent_at is missing — run: php artisan migrate');

            return self::FAILURE;
        }

        $targetDate = now()->addDays(3)->toDateString();

        $events = Event::query()
            ->where('is_published', true)
            ->whereNull('reminder_sms_sent_at')
            ->where('event_type', '!=', 'announcement')
            ->where(function ($query) use ($targetDate) {
                $query->whereDate('event_date', $targetDate)
                    ->orWhere(function ($inner) use ($targetDate) {
                        $inner->whereNull('event_date')->whereDate('starts_at', $targetDate);
                    });
            })
            ->get();

        if ($events->isEmpty()) {
            $this->info("No published events start on {$targetDate} — nothing to remind.");

            return self::SUCCESS;
        }

        $totalSent = 0;

        foreach ($events as $event) {
            if ($this->option('dry')) {
                $this->line("[dry] Would remind: #{$event->id} \"{$event->title}\" ({$event->barangay_target})");
                continue;
            }

            $sent = $sms->sendReminderSms($event);
            $totalSent += $sent;
            $this->info("Reminded #{$event->id} \"{$event->title}\" — {$sent} recipient(s).");
        }

        if (!$this->option('dry')) {
            $this->info("Done. {$events->count()} event(s), {$totalSent} SMS recipient(s) total.");
        }

        return self::SUCCESS;
    }
}
