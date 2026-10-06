<?php
// app/Console/Commands/SendQueuePressureAlerts.php
//
// Every minute: has an RHU's queue become heavy or over capacity, or is an
// event nearly full? If so, tell that RHU's staff (StaffAlertService), once
// per rise -- not every minute while it stays that way.
//
// The levels are the dashboard map's (App\Support\QueuePressure): 26+
// waiting is heavy, 51+ over capacity; an event at 71% of its slots is
// filling up, at 91% almost full.
//
//   php artisan queue:pressure-alerts        # check and alert
//   php artisan queue:pressure-alerts --dry  # print each level, alert nobody

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Notification\StaffAlertService;
use App\Support\EventFacility;
use App\Support\QueuePressure;
use App\Support\Rhu;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SendQueuePressureAlerts extends Command
{
    protected $signature = 'queue:pressure-alerts {--dry : Print each level without alerting anyone}';

    protected $description = "Alert an RHU's staff when its queue becomes heavy or over capacity, or an event is nearly full";

    /** The same level is not alerted again within this time (a queue hovering at 25-26 waiting). */
    private const REPEAT_AFTER_MINUTES = 30;

    public function handle(StaffAlertService $alerts): int
    {
        foreach (Rhu::ids() as $rhuId) {
            $counts = QueuePressure::countsFor($rhuId);
            $level = QueuePressure::queueLevel($counts['queue'], $counts['waiting'], $counts['in_service'], $counts['priority']);

            $this->line(sprintf('RHU %d: %s (%d waiting, %d in service, %d priority)', $rhuId, $level, $counts['waiting'], $counts['in_service'], $counts['priority']));

            if ($this->shouldAlert("queue-pressure:{$rhuId}", $level)) {
                $sent = $alerts->queuePressure($rhuId, $level, $counts);
                $this->info("  alerted {$sent} staff");
            }
        }

        $events = Event::query()
            ->published()
            ->where('event_type', '!=', 'announcement')
            ->where('max_slots', '>', 0)
            ->notEnded()
            ->with('creator')
            ->withCount(['registrations as taken' => fn ($query) => $query->whereIn('status', [
                EventRegistration::STATUS_REGISTERED,
                EventRegistration::STATUS_ATTENDED,
            ])])
            ->get();

        foreach ($events as $event) {
            $level = QueuePressure::eventLevel((int) $event->taken, (int) $event->max_slots);

            $this->line(sprintf('Event #%d: %s (%d of %d)', $event->id, $level, $event->taken, $event->max_slots));

            if ($this->shouldAlert("event-crowding:{$event->id}", $level)) {
                $sent = $alerts->eventCrowding($event, EventFacility::hostRhuId($event), $level, (int) $event->taken, (int) $event->max_slots);
                $this->info("  alerted {$sent} staff");
            }
        }

        return self::SUCCESS;
    }

    /**
     * A rise worth an alert: high or critical, above the level seen last
     * minute, and not this same level alerted in the last 30 minutes. The
     * level is remembered either way (for a day), except in a dry run.
     */
    private function shouldAlert(string $key, string $level): bool
    {
        $state = Cache::get($key, ['rank' => 0, 'alerted' => []]);
        $rank = QueuePressure::rank($level);
        $alert = false;

        if ($rank >= QueuePressure::rank(QueuePressure::HIGH) && $rank > (int) $state['rank']) {
            $last = $state['alerted'][$level] ?? null;
            $alert = $last === null || Carbon::parse($last)->diffInMinutes(now()) >= self::REPEAT_AFTER_MINUTES;

            if ($alert) {
                $state['alerted'][$level] = now()->toIso8601String();
            }
        }

        if ($this->option('dry')) {
            return false;
        }

        $state['rank'] = $rank;
        Cache::put($key, $state, now()->addDay());

        return $alert;
    }
}
