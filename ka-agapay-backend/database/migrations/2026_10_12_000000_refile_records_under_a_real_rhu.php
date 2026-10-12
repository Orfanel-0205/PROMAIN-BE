<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records filed under an RHU that does not exist.
 *
 * Until Oct 2026 a follow-up, feedback or appointment whose RHU was unknown
 * took the resident's BARANGAY id instead (FollowUpReminderController,
 * FeedbackController), and records from June carry it from before the rhus
 * table. Production had 14 rows under "RHU 21", which is barangay Buto. No
 * RHU's staff could see them.
 *
 * Each is refiled under the RHU of the record it came from (consultation,
 * appointment or queue ticket), else the first RHU -- where the appointment
 * list already showed such rows. The code that wrote them is fixed in the
 * same change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rhus')) {
            return;
        }

        $ids = DB::table('rhus')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return;
        }

        $fallback = Schema::hasColumn('rhus', 'is_active')
            ? (int) (DB::table('rhus')->where('is_active', true)->min('id') ?? min($ids))
            : min($ids);

        $valid = fn ($rhuId) => in_array((int) $rhuId, $ids, true) ? (int) $rhuId : null;

        $rhuOf = function (string $table, $id) use ($valid) {
            if (!$id || !Schema::hasTable($table) || !Schema::hasColumn($table, 'rhu_id')) {
                return null;
            }

            return $valid(DB::table($table)->where('id', $id)->value('rhu_id'));
        };

        // Appointments first: the others look theirs up.
        $plan = [
            'appointments' => fn ($row) => Schema::hasColumn('consultations', 'appointment_id')
                ? $valid(DB::table('consultations')->where('appointment_id', $row->id)->whereNotNull('rhu_id')->value('rhu_id'))
                : null,
            'queue_tickets' => fn ($row) => $rhuOf('appointments', $row->appointment_id ?? null),
            'telemedicine_requests' => fn ($row) => $rhuOf('appointments', $row->appointment_id ?? null)
                ?? $rhuOf('queue_tickets', $row->queue_ticket_id ?? null),
            'follow_up_reminders' => fn ($row) => $rhuOf('consultations', $row->consultation_id ?? null)
                ?? $rhuOf('appointments', $row->appointment_id ?? null),
            'service_feedback' => fn ($row) => $rhuOf('appointments', $row->appointment_id ?? null)
                ?? $rhuOf('queue_tickets', $row->queue_ticket_id ?? null),
        ];

        foreach ($plan as $table => $source) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'rhu_id')) {
                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull('rhu_id')
                ->whereNotIn('rhu_id', $ids)
                ->get();

            foreach ($rows as $row) {
                $rhuId = $source($row) ?? $fallback;

                DB::table($table)->where('id', $row->id)->update(['rhu_id' => $rhuId]);

                logger()->info('[migration] refiled under a real RHU', [
                    'table' => $table,
                    'id' => $row->id,
                    'from' => $row->rhu_id,
                    'to' => $rhuId,
                ]);
            }
        }
    }

    /**
     * Not reversible: the old values were not RHUs. They are in the log and
     * in the backup taken before deploying.
     */
    public function down(): void
    {
    }
};
