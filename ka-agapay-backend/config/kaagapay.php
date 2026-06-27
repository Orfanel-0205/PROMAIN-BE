<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Completed-record board visibility / report retention
    |--------------------------------------------------------------------------
    |
    | completed_board_visible_days
    |   How long a COMPLETED appointment / telemedicine record keeps showing on
    |   the default ACTIVE board after completion. After this it moves to the
    |   Completed / History views (never deleted).
    |
    | report_retention_days
    |   Minimum window completed records must remain queryable for reports/
    |   history before they may be marked archived for board cleanup. Records are
    |   archived (hidden from active board), never hard-deleted.
    */

    'completed_board_visible_days' => (int) env('KAAGAPAY_COMPLETED_BOARD_VISIBLE_DAYS', 3),

    'report_retention_days' => (int) env('KAAGAPAY_REPORT_RETENTION_DAYS', 30),

];
