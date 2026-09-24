<?php
// app/Services/Analytics/OutbreakDetector.php

namespace App\Services\Analytics;

use App\Support\ComplaintGrouping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Noticing when several people in one barangay report the same thing.
 *
 * WHY NOT THE EXISTING ENGINE
 * ---------------------------
 * HeatmapAlertService already implements outbreak detection by baseline
 * deviation: a spike is two times the four-week rolling average. That is the
 * right method for a municipality with years of records, and the wrong one
 * here. Against the volumes this system actually holds -- a handful of
 * consultations a week -- a baseline of 0.25 cases makes a single patient a
 * four-fold spike, and the alerts would be noise from the first day. It is
 * also never called by anything, so nothing has been comparing against
 * anything.
 *
 * This detector answers the question the RHU actually asked: three or more
 * consultations for the same condition, in the same barangay, within seven
 * days. An absolute count is crude, and crude is what works when the numbers
 * are small. It is honest about being a prompt to go and look rather than a
 * statistical claim.
 *
 * Notifiable conditions -- dengue, measles, cholera, typhoid, chickenpox --
 * trigger at two. Waiting for a third case of measles is waiting too long.
 */
class OutbreakDetector
{
    public const DEFAULT_WINDOW_DAYS = 7;
    public const DEFAULT_THRESHOLD = 3;

    /**
     * Clusters in the window that are not already flagged.
     *
     * Reads consultations rather than barangay_heatmaps: that table is
     * populated by a separate aggregation and would add a second thing that
     * has to have run for this to work.
     *
     * @return array<int, array{
     *     barangay_id:int, barangay:string, condition:string,
     *     case_count:int, threshold:int, notifiable:bool, rhu_ids:array<int,int>
     * }>
     */
    public function detect(
        int $windowDays = self::DEFAULT_WINDOW_DAYS,
        int $threshold = self::DEFAULT_THRESHOLD
    ): array {
        if (!Schema::hasTable('consultations') || !Schema::hasTable('barangays')) {
            return [];
        }

        $since = Carbon::today()->subDays(max(1, $windowDays));

        $rows = $this->recentCases($since);

        if ($rows === []) {
            return [];
        }

        // barangay_id => condition => rows
        $grouped = [];

        foreach ($rows as $row) {
            $condition = ComplaintGrouping::normalize($row->complaint ?? null);

            // "Unspecified" is a blank complaint box, not a symptom several
            // people happen to share. Counting it would make the commonest
            // alert "several people came in".
            if ($condition === 'Unspecified') {
                continue;
            }

            $grouped[(int) $row->barangay_id][$condition][] = $row;
        }

        $clusters = [];

        foreach ($grouped as $barangayId => $byCondition) {
            foreach ($byCondition as $condition => $cases) {
                $required = ComplaintGrouping::threshold($condition, $threshold);

                if (count($cases) < $required) {
                    continue;
                }

                $clusters[] = [
                    'barangay_id' => $barangayId,
                    'barangay' => (string) ($cases[0]->barangay_name ?? "Barangay {$barangayId}"),
                    'condition' => $condition,
                    'case_count' => count($cases),
                    'threshold' => $required,
                    'notifiable' => ComplaintGrouping::isNotifiable($condition),
                    // Which facilities saw these patients, so the alert can say
                    // where to look rather than only what to look for.
                    'rhu_ids' => $this->facilityIds($cases),
                ];
            }
        }

        // Worst first: a notifiable condition, then the larger cluster.
        usort($clusters, function ($a, $b) {
            return [$b['notifiable'], $b['case_count']] <=> [$a['notifiable'], $a['case_count']];
        });

        return $clusters;
    }

    /**
     * Whether this barangay and condition already has an open alert.
     *
     * A cluster that is still growing would otherwise raise an alert every
     * morning until somebody resolved it, which is the fastest way to teach
     * staff to ignore the alerts.
     */
    public function alreadyFlagged(int $barangayId, string $condition, int $windowDays): bool
    {
        if (!Schema::hasTable('heatmap_alerts')) {
            return false;
        }

        return DB::table('heatmap_alerts')
            ->where('barangay_id', $barangayId)
            ->where('disease_type', $condition)
            ->where('is_resolved', false)
            ->where('created_at', '>=', Carbon::now()->subDays(max(1, $windowDays)))
            ->exists();
    }

    /**
     * Consultations in the window, with the patient's barangay.
     *
     * @return array<int, object>
     */
    private function recentCases(Carbon $since): array
    {
        $complaint = Schema::hasColumn('consultations', 'chief_complaint')
            ? 'c.chief_complaint'
            : (Schema::hasColumn('consultations', 'diagnosis') ? 'c.diagnosis' : null);

        $hasKey = Schema::hasColumn('users', 'barangay_id');
        $hasName = Schema::hasColumn('users', 'barangay');

        if ($complaint === null || (!$hasKey && !$hasName)) {
            return [];
        }

        $dateColumn = Schema::hasColumn('consultations', 'consultation_date')
            ? 'c.consultation_date'
            : 'c.created_at';

        /*
         * Two ways a patient names their barangay.
         *
         * users.barangay_id is the foreign key, and only two of the
         * forty-nine accounts on this system have one. The rest carry the
         * barangay as free text in users.barangay, which is what the
         * analytics screens have always read.
         *
         * An inner join on the key alone therefore matched nothing and the
         * detector reported no clusters however far back it looked. Both
         * are resolved here: the key when it is set, otherwise the name,
         * matched case- and whitespace-insensitively.
         *
         * A row that resolves to neither is dropped. heatmap_alerts keys
         * on barangay_id, so an alert for a barangay this system cannot
         * identify has nowhere to go.
         */
        $barangay = 'COALESCE(bk.barangay_id, bn.barangay_id)';
        $barangayName = 'COALESCE(bk.name, bn.name)';

        return DB::table('consultations as c')
            ->join('users as u', 'u.user_id', '=', 'c.user_id')
            ->leftJoin('barangays as bk', 'bk.barangay_id', '=', 'u.barangay_id')
            ->leftJoin('barangays as bn', function ($join) {
                $join->on(
                    DB::raw('LOWER(TRIM(bn.name))'),
                    '=',
                    DB::raw('LOWER(TRIM(u.barangay))')
                );
            })
            ->whereDate($dateColumn, '>=', $since->toDateString())
            ->whereNotNull($complaint)
            ->whereRaw("{$barangay} IS NOT NULL")
            ->select([
                DB::raw("{$barangay} as barangay_id"),
                DB::raw("{$barangayName} as barangay_name"),
                DB::raw("{$complaint} as complaint"),
                'c.rhu_id',
            ])
            ->get()
            ->all();
    }

    /** @param array<int, object> $cases @return array<int, int> */
    private function facilityIds(array $cases): array
    {
        $ids = [];

        foreach ($cases as $case) {
            if ($case->rhu_id !== null) {
                $ids[(int) $case->rhu_id] = true;
            }
        }

        return array_keys($ids);
    }
}
