<?php
// app/Http/Controllers/Api/AdminBackupController.php
//
// Phase 1 — read-only backup status for the Settings page.
//
// This endpoint reports; it does not act. There is deliberately no POST that
// triggers a dump: pg_dump on a request thread would block a PHP-FPM worker for
// as long as the dump takes and compete with live traffic for the same database
// it is reading. Backups are cron's job. The panel's honesty comes from the fact
// that nothing a browser does can create a row in backup_runs.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminBackupController extends Controller
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT     = 50;

    public function status(Request $request): JsonResponse
    {
        // The table arrives with this pass's migration. If the API is deployed
        // ahead of `migrate --force`, say so plainly rather than 500-ing.
        if (!Schema::hasTable('backup_runs')) {
            return response()->json([
                'configured'   => false,
                'health'       => 'unknown',
                'message'      => 'Backup tracking is not installed on this server yet (pending migration).',
                'last_success' => null,
                'runs'         => [],
            ]);
        }

        $limit = min(
            self::MAX_LIMIT,
            max(1, (int) $request->query('limit', self::DEFAULT_LIMIT))
        );

        $runs = BackupRun::orderByDesc('started_at')->limit($limit)->get();

        $lastSuccess = BackupRun::where('status', BackupRun::STATUS_SUCCESS)
            ->orderByDesc('started_at')
            ->first();

        return response()->json([
            'configured'        => true,
            'health'            => $this->health($lastSuccess),
            'stale_after_hours' => (int) config('backup.stale_after_hours', BackupRun::STALE_AFTER_HOURS),
            'last_success'      => $lastSuccess ? $this->present($lastSuccess) : null,
            'runs'              => $runs->map(fn (BackupRun $run) => $this->present($run))->values(),
        ]);
    }

    /**
     * One of: never | healthy | stale | unprotected.
     *
     * 'unprotected' is its own state on purpose. A dump that succeeded but never
     * reached off-site storage still lives only on the droplet it protects
     * against, so it must not read as green.
     */
    private function health(?BackupRun $lastSuccess): string
    {
        if (!$lastSuccess) {
            return 'never';
        }

        $staleHours = (int) config('backup.stale_after_hours', BackupRun::STALE_AFTER_HOURS);

        if ($lastSuccess->started_at->lt(now()->subHours($staleHours))) {
            return 'stale';
        }

        return $lastSuccess->isProtective() ? 'healthy' : 'unprotected';
    }

    private function present(BackupRun $run): array
    {
        return [
            'id'               => $run->id,
            'started_at'       => optional($run->started_at)->toIso8601String(),
            'finished_at'      => optional($run->finished_at)->toIso8601String(),
            'status'           => $run->status,
            'offsite_status'   => $run->offsite_status,
            'file_name'        => $run->file_name,
            'file_size_bytes'  => $run->file_size_bytes,
            'duration_seconds' => $run->durationSeconds(),
            'trigger'          => $run->trigger,
            'error_message'    => $run->error_message,
        ];
    }
}
