<?php
// app/Models/BackupRun.php
//
// One row per `backup:run` invocation. Written by the artisan command, read by
// GET /api/v1/admin/backups/status. Nothing in the app writes to this table
// from an HTTP request — a backup is something that happened on the droplet,
// not something a browser can assert.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    public const OFFSITE_PENDING  = 'pending';
    public const OFFSITE_UPLOADED = 'uploaded';
    public const OFFSITE_FAILED   = 'failed';
    public const OFFSITE_SKIPPED  = 'skipped';

    /**
     * A backup older than this is treated as stale by the status endpoint.
     * Nightly cron plus a wide margin for a late or slow run.
     */
    public const STALE_AFTER_HOURS = 36;

    protected $table = 'backup_runs';

    protected $fillable = [
        'started_at',
        'finished_at',
        'status',
        'file_name',
        'file_size_bytes',
        'offsite_status',
        'error_message',
        'trigger',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
        'file_size_bytes' => 'integer',
    ];

    public function durationSeconds(): ?int
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    /**
     * A run only counts as protective if the dump succeeded AND the file left
     * the droplet. 'pending' is deliberately not success: it means the off-site
     * step has not reported back yet.
     */
    public function isProtective(): bool
    {
        return $this->status === self::STATUS_SUCCESS
            && in_array($this->offsite_status, [self::OFFSITE_UPLOADED, self::OFFSITE_SKIPPED], true);
    }
}
