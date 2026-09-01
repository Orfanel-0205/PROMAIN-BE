<?php
// app/Console/Commands/RecordBackupOffsiteResult.php
//
// Phase 1 — the second half of a real backup.
//
// `backup:run` proves a dump was produced. This command proves it LEFT THE
// DROPLET. They are separate because they fail separately and for different
// reasons: pg_dump succeeds while object-storage credentials have expired, and
// the resulting file sits on exactly the machine whose failure it was supposed
// to protect against.
//
// Called by docs/deploy/kaagapay-backup.sh immediately after its upload step.

namespace App\Console\Commands;

use App\Models\BackupRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecordBackupOffsiteResult extends Command
{
    protected $signature = 'backup:offsite
        {result : uploaded|failed}
        {--message= : Failure detail to store (never include credentials)}
        {--run= : Specific backup_runs id; defaults to the newest successful run}';

    protected $description = 'Record whether the latest database dump reached off-site storage.';

    public function handle(): int
    {
        $result = strtolower((string) $this->argument('result'));

        if (!in_array($result, [BackupRun::OFFSITE_UPLOADED, BackupRun::OFFSITE_FAILED], true)) {
            $this->error("Result must be 'uploaded' or 'failed'.");

            return self::FAILURE;
        }

        $run = $this->option('run')
            ? BackupRun::find((int) $this->option('run'))
            : BackupRun::where('status', BackupRun::STATUS_SUCCESS)->latest('started_at')->first();

        if (!$run) {
            $this->error('No matching backup run found to annotate.');

            return self::FAILURE;
        }

        $message = $this->option('message')
            ? mb_substr((string) $this->option('message'), 0, 2000)
            : null;

        $run->update([
            'offsite_status' => $result,
            // Keep any dump-stage error rather than overwriting it with an
            // upload-stage one; only fill in when the field is still empty.
            'error_message'  => $run->error_message ?: $message,
        ]);

        if ($result === BackupRun::OFFSITE_FAILED) {
            // error level: a dump stranded on the droplet protects nothing.
            Log::error('[backup] Off-site copy FAILED — dump exists only on the droplet.', [
                'backup_run_id' => $run->id,
                'file_name'     => $run->file_name,
                'reason'        => $message,
            ]);

            $this->error("Recorded off-site FAILURE for backup run #{$run->id}.");

            return self::FAILURE;
        }

        $this->info("Recorded off-site upload for backup run #{$run->id}.");

        return self::SUCCESS;
    }
}
