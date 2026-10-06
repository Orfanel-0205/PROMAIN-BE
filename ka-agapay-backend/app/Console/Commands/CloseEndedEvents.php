<?php
// app/Console/Commands/CloseEndedEvents.php
//
// Every 15 minutes: an event that has ended has its report announced to its
// RHU's staff and to whoever posted it -- once, recorded in
// events.report_generated_at and the audit trail. The report itself is
// built from the live records when opened (EventReportService), so
// attendance marked or items recorded later still count.
//
// By then the event has already dropped off the residents' list and the
// heatmap (Event::hasEnded). Nothing is deleted: the dashboard keeps it under
// Past / History.
//
// An event that ended more than two days before this first sees it -- the
// backlog on the day this was deployed -- is marked without an alert, so
// nobody opens the dashboard to a pile of old reports.
//
//   php artisan events:close-ended        # announce
//   php artisan events:close-ended --dry  # list what would be announced

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Audit\AuditActions;
use App\Services\Audit\AuditService;
use App\Services\Events\EventReportService;
use App\Services\Notification\StaffAlertService;
use App\Support\EventFacility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CloseEndedEvents extends Command
{
    protected $signature = 'events:close-ended {--dry : List the events without announcing anything}';

    protected $description = 'Announce the report of each event that has ended to its RHU staff';

    private const QUIET_AFTER_DAYS = 2;

    public function handle(StaffAlertService $alerts, EventReportService $reports, AuditService $audit): int
    {
        if (!Schema::hasColumn('events', 'report_generated_at')) {
            $this->error('events.report_generated_at is missing; run: php artisan migrate');

            return self::FAILURE;
        }

        $events = Event::query()
            ->published()
            ->where('event_type', '!=', 'announcement')
            ->whereNull('report_generated_at')
            ->with('creator')
            ->get()
            ->filter(fn (Event $event) => $event->hasEnded());

        foreach ($events as $event) {
            $quiet = $event->endTime()->lt(now()->subDays(self::QUIET_AFTER_DAYS));

            $this->line(sprintf('#%d %s: ended %s%s', $event->id, $event->title, $event->endTime()->toDateTimeString(), $quiet ? ' (old, no alert)' : ''));

            if ($this->option('dry')) {
                continue;
            }

            $summary = $reports->summary($event);

            $event->forceFill(['report_generated_at' => now()])->save();

            $sent = $quiet ? 0 : $alerts->eventReportReady($event, EventFacility::hostRhuId($event), $summary);

            $audit->info('events', AuditActions::EVENT_REPORT_GENERATED, [
                'subject_type' => 'event',
                'subject_id' => $event->id,
                'subject_label' => $event->title,
                'new_values' => $summary + ['staff_alerted' => $sent],
            ]);

            $this->info("  report announced to {$sent} staff");
        }

        return self::SUCCESS;
    }
}
