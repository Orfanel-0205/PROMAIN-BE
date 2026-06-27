<?php
// app/Support/BoardVisibility.php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Centralized, NON-DESTRUCTIVE completed-record board visibility.
 *
 * When a record is completed we stamp completed_at + board_visible_until
 * (= completed_at + KAAGAPAY_COMPLETED_BOARD_VISIBLE_DAYS). The ACTIVE board
 * hides completed records once board_visible_until has passed, EXCEPT when a
 * pending follow-up keeps them visible (evaluated live at query time).
 *
 * Nothing is ever deleted — records stay in Completed/History and reports.
 */
class BoardVisibility
{
    public static function visibleDays(): int
    {
        return max(0, (int) config('kaagapay.completed_board_visible_days', 3));
    }

    public static function retentionDays(): int
    {
        return max(1, (int) config('kaagapay.report_retention_days', 30));
    }

    /**
     * Stamp completed_at + board_visible_until on a completed appointment.
     * Safe + idempotent: only touches columns that exist; never throws.
     */
    public static function stampAppointmentCompleted(?int $appointmentId): void
    {
        if (!$appointmentId || $appointmentId <= 0) {
            return;
        }

        if (!Schema::hasTable('appointments') || !Schema::hasColumn('appointments', 'completed_at')) {
            return;
        }

        try {
            $row = DB::table('appointments')
                ->where('id', $appointmentId)
                ->first(['id', 'completed_at']);

            if (!$row) {
                return;
            }

            $completedAt = $row->completed_at
                ? Carbon::parse($row->completed_at)
                : now();

            $updates = ['completed_at' => $completedAt];

            if (Schema::hasColumn('appointments', 'board_visible_until')) {
                $updates['board_visible_until'] = $completedAt->copy()->addDays(self::visibleDays());
            }

            if (Schema::hasColumn('appointments', 'updated_at')) {
                $updates['updated_at'] = now();
            }

            DB::table('appointments')->where('id', $appointmentId)->update($updates);
        } catch (Throwable $e) {
            logger()->warning('[BoardVisibility] appointment stamp failed.', [
                'appointment_id' => $appointmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stamp completed_at + board_visible_until on a completed telemedicine request.
     */
    public static function stampTelemedicineRequestCompleted(?int $requestId): void
    {
        if (!$requestId || $requestId <= 0) {
            return;
        }

        if (
            !Schema::hasTable('telemedicine_requests')
            || !Schema::hasColumn('telemedicine_requests', 'completed_at')
        ) {
            return;
        }

        try {
            $row = DB::table('telemedicine_requests')
                ->where('id', $requestId)
                ->first(['id', 'completed_at']);

            if (!$row) {
                return;
            }

            $completedAt = $row->completed_at
                ? Carbon::parse($row->completed_at)
                : now();

            $updates = ['completed_at' => $completedAt];

            if (Schema::hasColumn('telemedicine_requests', 'board_visible_until')) {
                $updates['board_visible_until'] = $completedAt->copy()->addDays(self::visibleDays());
            }

            if (Schema::hasColumn('telemedicine_requests', 'updated_at')) {
                $updates['updated_at'] = now();
            }

            DB::table('telemedicine_requests')->where('id', $requestId)->update($updates);
        } catch (Throwable $e) {
            logger()->warning('[BoardVisibility] telemedicine request stamp failed.', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
