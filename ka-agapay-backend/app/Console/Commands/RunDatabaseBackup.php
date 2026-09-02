<?php
// app/Console/Commands/RunDatabaseBackup.php
//
// Phase 1 — the real nightly backup.
//
// Runs pg_dump, records exactly one backup_runs row describing what happened,
// and prunes old dump FILES (never backup_runs rows — the history of what ran
// stays complete). Designed to be driven by cron via docs/deploy/
// kaagapay-backup.sh, which handles the off-site copy and reports its result
// back through `backup:offsite`.
//
// Two deliberate choices worth keeping:
//
//   1. The password is passed through the process ENVIRONMENT, never argv.
//      Anything on a command line is world-readable via `ps` for the duration
//      of the dump.
//
//   2. pg_dump is invoked with an argument ARRAY and compresses to .gz itself
//      (-Z). There is no shell pipeline, so no shell metacharacter in a
//      database name or path can be interpreted.
//
// Failures log at error level so they reach whatever the logging stack fans out
// to (see config/logging.php). A backup that fails silently is worse than no
// backup, because the status panel would keep showing the last good run.

namespace App\Console\Commands;

use App\Models\BackupRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class RunDatabaseBackup extends Command
{
    protected $signature = 'backup:run
        {--trigger=cron : Where this run came from (cron|manual)}';

    protected $description = 'Dump the PostgreSQL database, record the result, and prune old dump files.';

    public function handle(): int
    {
        $trigger = $this->option('trigger') === 'manual' ? 'manual' : 'cron';

        $connection = config('database.default');

        if ($connection !== 'pgsql') {
            $this->error("backup:run supports PostgreSQL only; the default connection is '{$connection}'.");

            return self::FAILURE;
        }

        $db = config("database.connections.{$connection}");

        $directory = rtrim((string) config('backup.path'), '/\\');
        $fileName  = sprintf('%s_%s.sql.gz', $db['database'] ?? 'database', now()->format('Y-m-d_His'));
        $fullPath  = $directory . DIRECTORY_SEPARATOR . $fileName;

        // Record the attempt BEFORE doing the work. If the process is killed
        // mid-dump (OOM, reboot), the row survives as status 'running' with no
        // finished_at, which is visibly different from "no run happened".
        $run = BackupRun::create([
            'started_at'     => now(),
            'status'         => BackupRun::STATUS_RUNNING,
            'file_name'      => $fileName,
            'offsite_status' => config('backup.offsite_enabled')
                ? BackupRun::OFFSITE_PENDING
                : BackupRun::OFFSITE_SKIPPED,
            'trigger'        => $trigger,
        ]);

        if (!$this->ensureDirectory($directory, $run)) {
            return self::FAILURE;
        }

        try {
            $process = new Process(
                command: [
                    (string) config('backup.pg_dump_bin'),
                    '--host=' . ($db['host'] ?? '127.0.0.1'),
                    '--port=' . ($db['port'] ?? 5432),
                    '--username=' . ($db['username'] ?? ''),
                    '--dbname=' . ($db['database'] ?? ''),
                    '--no-password',   // fail fast instead of hanging on a prompt under cron
                    '--format=plain',
                    '--compress=6',    // pg_dump gzips the output itself — no shell pipe needed
                    '--file=' . $fullPath,
                ],
                env: [
                    // Credentials travel in the environment, never on argv.
                    'PGPASSWORD' => (string) ($db['password'] ?? ''),
                    'PGSSLMODE'  => (string) ($db['sslmode'] ?? 'prefer'),
                ],
                timeout: (float) config('backup.timeout'),
            );

            $process->run();

            if (!$process->isSuccessful()) {
                return $this->fail($run, $this->scrub(trim($process->getErrorOutput()) ?: 'pg_dump exited non-zero.'));
            }

            if (!is_file($fullPath)) {
                return $this->fail($run, 'pg_dump reported success but produced no file.');
            }

            $size = (int) filesize($fullPath);

            // A dump this small is not a database — it is an empty file or a
            // header with an error underneath. Treat it as a failure so the
            // panel never shows a reassuring green row for a useless file.
            if ($size < 1024) {
                return $this->fail($run, "Dump file is implausibly small ({$size} bytes); treating as failed.");
            }

            $run->update([
                'finished_at'     => now(),
                'status'          => BackupRun::STATUS_SUCCESS,
                'file_size_bytes' => $size,
            ]);

            $this->info(sprintf(
                'Backup complete: %s (%s) in %ds.',
                $fileName,
                $this->humanBytes($size),
                $run->fresh()->durationSeconds() ?? 0
            ));

            // Machine-readable so the cron wrapper can pin `backup:offsite` to
            // THIS run rather than "the newest successful one", which would be
            // ambiguous if another run started in between.
            $this->line('BACKUP_RUN_ID=' . $run->id);

            $this->prune($directory);

            return self::SUCCESS;
        } catch (ProcessTimedOutException $e) {
            return $this->fail($run, 'pg_dump timed out after ' . config('backup.timeout') . 's.');
        } catch (Throwable $e) {
            return $this->fail($run, $this->scrub($e->getMessage()));
        }
    }

    private function ensureDirectory(string $directory, BackupRun $run): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        if (@mkdir($directory, 0750, true) || is_dir($directory)) {
            return true;
        }

        $this->fail($run, "Backup directory is missing and could not be created: {$directory}");

        return false;
    }

    /**
     * Mark the run failed, log loudly, and report a non-zero exit so cron's own
     * mail (if configured) also fires.
     */
    private function fail(BackupRun $run, string $message): int
    {
        $message = mb_substr($message, 0, 2000);

        $run->update([
            'finished_at'   => now(),
            'status'        => BackupRun::STATUS_FAILED,
            'error_message' => $message,
        ]);

        // error level, so this reaches every channel in the logging stack.
        Log::error('[backup] Database backup FAILED.', [
            'backup_run_id' => $run->id,
            'file_name'     => $run->file_name,
            'reason'        => $message,
        ]);

        $this->error("Backup failed: {$message}");

        return self::FAILURE;
    }

    /**
     * Delete dump FILES older than the retention window. backup_runs rows are
     * never deleted here — the record of what ran outlives the file.
     */
    private function prune(string $directory): void
    {
        $keepDays = max(1, (int) config('backup.keep_days'));
        $cutoff   = now()->subDays($keepDays)->getTimestamp();
        $removed  = 0;

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.sql.gz') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->line("Pruned {$removed} dump file(s) older than {$keepDays} days.");
        }
    }

    /**
     * pg_dump error output can echo the connection string. Strip anything that
     * looks like a password before it reaches the database or the log.
     */
    private function scrub(string $message): string
    {
        return (string) preg_replace(
            '/(password|pgpassword)\s*[=:]\s*\S+/i',
            '$1=[redacted]',
            $message
        );
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) return round($bytes / 1_073_741_824, 2) . ' GB';
        if ($bytes >= 1_048_576)     return round($bytes / 1_048_576, 2) . ' MB';
        if ($bytes >= 1024)          return round($bytes / 1024, 2) . ' KB';

        return $bytes . ' B';
    }
}
