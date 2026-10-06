<?php
// app/Services/Events/EventReportService.php
//
// THE RECORD OF AN EVENT, for the RHU to account for it afterwards: who
// registered, who actually came (marked on the registrants page), and what
// was handed out (stock-outs tagged with the event on the inventory page).
//
// Built from the live records each time it is opened, so attendance marked
// or a stock-out recorded after the event still counts. Every attendance
// mark and every stock-out is in the audit trail with who made it.

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Support\EventFacility;
use App\Support\Rhu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EventReportService
{
    /**
     * The headline numbers (also sent in the "report ready" alert).
     *
     * @return array{registered: int, attended: int, no_show: int, not_marked: int, cancelled: int, items_dispensed: int}
     */
    public function summary(Event $event): array
    {
        $statuses = EventRegistration::query()
            ->where('event_id', $event->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $attended = (int) ($statuses[EventRegistration::STATUS_ATTENDED] ?? 0);
        $noShow = (int) ($statuses[EventRegistration::STATUS_NO_SHOW] ?? 0);
        $notMarked = (int) ($statuses[EventRegistration::STATUS_REGISTERED] ?? 0);

        return [
            // Everyone who registered and did not cancel.
            'registered' => $attended + $noShow + $notMarked,
            'attended' => $attended,
            'no_show' => $noShow,
            'not_marked' => $notMarked,
            'cancelled' => (int) ($statuses[EventRegistration::STATUS_CANCELLED] ?? 0),
            'items_dispensed' => (int) $this->dispensedQuery($event)->sum(DB::raw('ABS(t.quantity_changed)')),
        ];
    }

    /** @return array<string, mixed> */
    public function build(Event $event): array
    {
        $event->loadMissing('creator');
        $hostRhu = EventFacility::hostRhuId($event);

        $attendees = EventRegistration::query()
            ->with(['user:user_id,first_name,last_name,mobile_number'])
            ->where('event_id', $event->id)
            ->where('status', '!=', EventRegistration::STATUS_CANCELLED)
            ->orderBy('status')
            ->orderBy('registered_at')
            ->get();

        $markers = DB::table('users')
            ->whereIn('user_id', $attendees->pluck('attendance_marked_by')->filter()->unique())
            ->get(['user_id', 'first_name', 'last_name'])
            ->keyBy('user_id');

        $barangays = $this->barangayNames($attendees->pluck('user_id')->unique()->all());

        $dispensed = $this->dispensedQuery($event)
            ->leftJoin('users as u', 'u.user_id', '=', 't.performed_by')
            ->orderBy('t.created_at')
            ->get([
                't.id',
                'i.name as item',
                'i.unit_of_measure as unit',
                DB::raw('ABS(t.quantity_changed) as quantity'),
                't.reason',
                't.notes',
                't.created_at',
                'u.first_name',
                'u.last_name',
            ]);

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'category' => $event->category,
                'location' => $event->location,
                'barangays' => $event->barangay_target ?? 'all',
                'services' => $event->services ?? [],
                'starts_at' => optional($event->starts_at ?? $event->event_date)->toISOString(),
                'ends_at' => optional($event->endTime())->toISOString(),
                'has_ended' => $event->hasEnded(),
                'max_slots' => $event->max_slots,
                'host_rhu' => $hostRhu ? Rhu::rhuLabel($hostRhu) : 'All RHUs',
                'posted_by' => $event->creator
                    ? trim($event->creator->first_name . ' ' . $event->creator->last_name)
                    : null,
                'report_generated_at' => optional($event->report_generated_at)->toISOString(),
            ],
            'summary' => $this->summary($event),
            'attendees' => $attendees->map(fn (EventRegistration $row) => [
                'id' => $row->id,
                'name' => $row->user
                    ? trim($row->user->first_name . ' ' . $row->user->last_name)
                    : 'Unknown resident',
                'barangay' => $barangays[$row->user_id] ?? null,
                'status' => $row->status,
                'registered_at' => optional($row->registered_at)->toISOString(),
                'marked_by' => ($marker = $markers[$row->attendance_marked_by] ?? null)
                    ? trim($marker->first_name . ' ' . $marker->last_name)
                    : null,
                'marked_at' => optional($row->attendance_marked_at)->toISOString(),
            ])->values(),
            'dispensed' => $dispensed->map(fn ($row) => [
                'id' => (int) $row->id,
                'item' => $row->item,
                'unit' => $row->unit,
                'quantity' => (int) $row->quantity,
                'reason' => $row->reason,
                'notes' => $row->notes,
                'recorded_by' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: null,
                'recorded_at' => \Illuminate\Support\Carbon::parse($row->created_at)->toISOString(),
            ])->values(),
            'dispensed_totals' => $dispensed
                ->groupBy(fn ($row) => $row->item . '|' . $row->unit)
                ->map(fn ($rows) => [
                    'item' => $rows->first()->item,
                    'unit' => $rows->first()->unit,
                    'quantity' => (int) $rows->sum('quantity'),
                ])
                ->sortBy('item')
                ->values(),
            'generated_at' => now()->toISOString(),
        ];
    }

    private function dispensedQuery(Event $event)
    {
        return DB::table('inventory_transactions as t')
            ->join('inventory_items as i', 'i.id', '=', 't.inventory_item_id')
            ->where('t.event_id', $event->id)
            ->where('t.transaction_type', 'stock_out');
    }

    /** @return array<int, string> user_id => barangay name */
    private function barangayNames(array $userIds): array
    {
        if ($userIds === [] || !Schema::hasTable('resident_profiles')) {
            return [];
        }

        return DB::table('resident_profiles as rp')
            ->join('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
            ->whereIn('rp.user_id', $userIds)
            ->pluck('b.name', 'rp.user_id')
            ->map(fn ($name) => trim((string) $name))
            ->all();
    }
}
