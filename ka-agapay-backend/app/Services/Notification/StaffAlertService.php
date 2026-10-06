<?php
// app/Services/Notification/StaffAlertService.php
//
// ALERTS TO AN RHU'S STAFF: a queue that has become heavy or over capacity,
// an event that is nearly full, and an event report that is ready.
//
// Who: every active staff account at that RHU, plus the MHO and the super
// admin, who oversee every RHU (the RHU's choice, Oct 2026). "At that RHU" is
// the RHU each person's own lists are scoped to (Rhu::filterRhuId), so an
// alert reaches exactly the people whose dashboard shows the problem. An RHU
// of null means every RHU's staff.
//
// In-app notifications: the dashboard checks every 15 seconds, shows a
// banner and plays the urgent tone (DashboardShell), and each person's
// notification preferences apply.

namespace App\Services\Notification;

use App\Models\Event;
use App\Models\User;
use App\Notifications\NotificationTypes;
use App\Support\QueuePressure;
use App\Support\Rhu;
use Illuminate\Support\Collection;

class StaffAlertService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @return Collection<int, User> */
    public function staffFor(?int $rhuId): Collection
    {
        return User::query()
            ->with('role')
            ->where('account_status', 'active')
            ->get()
            ->filter(fn (User $user) => $user->isStaffAccount()
                && ($rhuId === null
                    || $user->isGlobalRhuScope()
                    || Rhu::filterRhuId($user, null) === $rhuId))
            ->values();
    }

    /** @param array{queue: int, waiting: int, in_service: int, priority: int} $counts */
    public function queuePressure(int $rhuId, string $level, array $counts): int
    {
        $rhu = Rhu::rhuLabel($rhuId) ?? "RHU {$rhuId}";

        $title = $level === QueuePressure::CRITICAL
            ? "{$rhu} queue is over capacity"
            : "{$rhu} queue is getting heavy";

        $message = sprintf(
            '%d waiting, %d in service, %d priority. Suggested: %s.',
            $counts['waiting'],
            $counts['in_service'],
            $counts['priority'],
            QueuePressure::action($level, $counts['priority'])
        );

        return $this->send($this->staffFor($rhuId), NotificationTypes::QUEUE_OVERLOAD, $title, $message, [
            'rhu_id' => $rhuId,
            'level' => $level,
            ...$counts,
        ], '/queue');
    }

    public function eventCrowding(Event $event, ?int $rhuId, string $level, int $registrants, int $slots): int
    {
        $title = $level === QueuePressure::CRITICAL
            ? "Event almost full: {$event->title}"
            : "Event filling up: {$event->title}";

        $message = "{$registrants} of {$slots} slots taken. "
            . ($level === QueuePressure::CRITICAL
                ? 'Prepare crowd control, or add slots or a second schedule.'
                : 'Keep staff ready for a large turnout.');

        return $this->send($this->staffFor($rhuId), NotificationTypes::EVENT_CROWDING, $title, $message, [
            'event_id' => $event->id,
            'level' => $level,
            'registrants' => $registrants,
            'slots' => $slots,
        ], "/cms/events/{$event->id}/registrants");
    }

    /** @param array<string, mixed> $summary EventReportService::summary() */
    public function eventReportReady(Event $event, ?int $rhuId, array $summary): int
    {
        $people = $this->staffFor($rhuId);

        // Whoever posted it hears about it even if they belong to another RHU.
        if ($event->creator && $people->doesntContain('user_id', $event->creator->user_id)) {
            $people->push($event->creator);
        }

        $message = sprintf(
            '%d attended of %d registered (%d no-show, %d not marked). %d item(s) dispensed.',
            $summary['attended'],
            $summary['registered'],
            $summary['no_show'],
            $summary['not_marked'],
            $summary['items_dispensed']
        );

        return $this->send($people, NotificationTypes::EVENT_REPORT_READY, "Event ended: {$event->title}", $message, [
            'event_id' => $event->id,
            ...$summary,
        ], "/cms/events/{$event->id}/report");
    }

    /** @param Collection<int, User> $people */
    private function send(Collection $people, string $type, string $title, string $message, array $meta, string $url): int
    {
        $sent = 0;

        foreach ($people as $user) {
            if ($this->notifications->notifyUser($user, $type, $title, $message, $meta, $url)) {
                $sent++;
            }
        }

        return $sent;
    }
}
