<?php
// database/migrations/2026_09_02_000000_create_backup_runs_table.php
//
// Phase 1 — replaces the Settings page's decorative "Backup & Retention" panel
// with something that reports what actually happened on the droplet.
//
// Before this table, "Backup Now" wrote a timestamp into the browser's own
// localStorage and "Last Backup" defaulted to a hardcoded string. Staff could
// read that panel and believe the database was protected when nothing backed up
// anything. One row is written here per `backup:run` invocation, so the panel
// can only ever show runs that really occurred.
//
// The dump file itself is NOT stored in the database — only the evidence that a
// dump was produced, how big it was, and whether it left the droplet. A dump
// that never leaves the machine it is backing up is not a backup, which is why
// offsite_status is tracked separately from status.
//
// Additive-only and guarded — creates a new table, alters nothing existing.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backup_runs')) {
            return;
        }

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();

            // Wall-clock span of the pg_dump. finished_at stays null while a run
            // is in flight, which is also how a killed run is recognised later:
            // status 'running' with a finished_at that never arrived.
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // running | success | failed
            $table->string('status', 20)->default('running');

            // Basename only. The absolute path is deployment-specific and would
            // go stale the moment the backup directory moves.
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            // pending  — dump succeeded, off-site copy has not reported yet
            // uploaded — confirmed off the droplet
            // failed   — the off-site copy was attempted and did not work
            // skipped  — no off-site destination configured on this host
            $table->string('offsite_status', 20)->default('pending');

            // Truncated at write time; never contains credentials.
            $table->text('error_message')->nullable();

            // cron | manual — so an operator can tell an automated nightly run
            // from someone testing by hand.
            $table->string('trigger', 20)->default('cron');

            $table->timestamps();

            // The status endpoint always reads "most recent first".
            $table->index(['started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
