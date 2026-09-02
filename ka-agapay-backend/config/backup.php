<?php
// config/backup.php
//
// Phase 1 — settings for the nightly `backup:run` command.
//
// These are deployment facts, not user preferences: they live in .env on the
// droplet, NOT in the Settings page. The Settings page reports what happened;
// it does not configure the schedule (cron owns that) or the retention window.

return [

    /*
    |--------------------------------------------------------------------------
    | Local dump directory
    |--------------------------------------------------------------------------
    | Where pg_dump writes before the off-site copy. Must be writable by the
    | user the cron entry runs as.
    */
    'path' => env('BACKUP_PATH', '/var/backups/kaagapay'),

    /*
    |--------------------------------------------------------------------------
    | Local retention
    |--------------------------------------------------------------------------
    | How many days of dump FILES to keep on the droplet. This is disk hygiene
    | only — it has nothing to do with the app's archive-never-delete policy for
    | patient records, and it never deletes backup_runs rows, so the history of
    | what ran stays complete even after the files themselves are pruned.
    */
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | pg_dump binary
    |--------------------------------------------------------------------------
    | Override when PostgreSQL client tools are not on the cron PATH — a very
    | common cause of "the backup works when I run it but not from cron".
    */
    'pg_dump_bin' => env('BACKUP_PG_DUMP_BIN', 'pg_dump'),

    /*
    |--------------------------------------------------------------------------
    | Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('BACKUP_TIMEOUT', 1800),

    /*
    |--------------------------------------------------------------------------
    | Off-site destination configured?
    |--------------------------------------------------------------------------
    | The shell wrapper (docs/deploy/kaagapay-backup.sh) does the actual upload
    | and reports the result back via `backup:offsite`. This flag only tells the
    | command what to record when no wrapper reports in: with an off-site target
    | configured, a run stays 'pending' until confirmed; without one, it is
    | honestly marked 'skipped' rather than silently looking healthy.
    */
    'offsite_enabled' => env('BACKUP_OFFSITE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Staleness threshold surfaced by the status endpoint
    |--------------------------------------------------------------------------
    */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 36),

];
