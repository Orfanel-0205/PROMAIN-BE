<?php
// app/Support/EventFacility.php
//
// WHERE AN EVENT IS, AND WHICH RHU IS HOSTING IT.
//
// Every RHU serves the whole of Malasiqui, so an event belongs to a place --
// the barangays it targets (Buto, Abonagan, ...) -- more than to a facility.
//
// pins(): the target barangays' points on the map. The dashboard's queue
// heatmap draws the event there (it used to draw every event at the RHU
// building, because the event form saved barangay names and never
// coordinates). An event for "all" barangays has no pins and stays at its
// host RHU.
//
// hostRhuId(): whose event it is, for alerts and for the map's facility
// pressure. In order: the RHU the post is restricted to; the RHU of the staff
// member who posted it; the RHU nearest its first barangay; otherwise none,
// meaning every RHU (an MHO or super admin posting for the whole town).

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EventFacility
{
    /** @return list<array{barangay: string, latitude: float, longitude: float}> */
    public static function pins(Event $event): array
    {
        $target = trim((string) ($event->barangay_target ?? 'all'));

        if ($target === '' || strtolower($target) === 'all') {
            return [];
        }

        $barangays = self::barangays();
        $pins = [];

        foreach (array_filter(array_map('trim', explode(',', $target))) as $name) {
            $row = $barangays[mb_strtolower($name)] ?? null;

            if ($row) {
                $pins[] = $row;
            }
        }

        return $pins;
    }

    public static function hostRhuId(Event $event): ?int
    {
        $restricted = Rhu::visibilityRhuId($event->visibility);

        if ($restricted) {
            return $restricted;
        }

        $creator = $event->creator;

        if ($creator && $creator->isStaffAccount() && !$creator->isGlobalRhuScope()) {
            return Rhu::filterRhuId($creator, null);
        }

        $pin = self::pins($event)[0] ?? null;

        return $pin ? self::nearestRhu($pin['latitude'], $pin['longitude']) : null;
    }

    private static function nearestRhu(float $latitude, float $longitude): ?int
    {
        $best = null;
        $bestDistance = INF;
        $scale = cos(deg2rad($latitude));

        foreach (self::facilities() as $facility) {
            $distance = ($facility['latitude'] - $latitude) ** 2
                + (($facility['longitude'] - $longitude) * $scale) ** 2;

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $facility['id'];
            }
        }

        return $best;
    }

    /** @return array<string, array{barangay: string, latitude: float, longitude: float}> */
    private static function barangays(): array
    {
        return once(function () {
            if (!Schema::hasColumns('barangays', ['latitude', 'longitude'])) {
                return [];
            }

            return DB::table('barangays')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get(['name', 'latitude', 'longitude'])
                ->mapWithKeys(fn ($row) => [mb_strtolower(trim((string) $row->name)) => [
                    'barangay' => trim((string) $row->name),
                    'latitude' => (float) $row->latitude,
                    'longitude' => (float) $row->longitude,
                ]])
                ->all();
        });
    }

    /** @return list<array{id: int, latitude: float, longitude: float}> */
    private static function facilities(): array
    {
        return once(function () {
            if (!Schema::hasTable('rhus') || !Schema::hasColumns('rhus', ['latitude', 'longitude'])) {
                return [];
            }

            return DB::table('rhus')
                ->where('is_active', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('id')
                ->get(['id', 'latitude', 'longitude'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'latitude' => (float) $row->latitude,
                    'longitude' => (float) $row->longitude,
                ])
                ->all();
        });
    }
}
