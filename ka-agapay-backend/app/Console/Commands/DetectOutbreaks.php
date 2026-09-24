<?php
// app/Console/Commands/DetectOutbreaks.php

namespace App\Console\Commands;

use App\Services\Analytics\OutbreakDetector;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Look for barangays where several people reported the same thing.
 *
 *     php artisan outbreak:detect
 *     php artisan outbreak:detect --dry-run
 *     php artisan outbreak:detect --days=14 --threshold=4
 *
 * Runs each morning before clinic, so an overnight cluster is on the board
 * when the first staff member opens the dashboard rather than being noticed
 * the following week.
 *
 * WHAT IT IS, AND IS NOT
 * ----------------------
 * It is a prompt to go and look. Three people from one barangay describing the
 * same complaint in a week may be an outbreak, a family, or a coincidence, and
 * this cannot tell which -- only that it is worth someone's attention. The
 * alert says so in those words, because an alert that overstates itself is
 * either acted on wrongly or learned to be ignored.
 */
class DetectOutbreaks extends Command
{
    protected $signature = 'outbreak:detect
        {--dry-run : Report what would be raised without writing or notifying}
        {--days= : Days to look back (default 7)}
        {--threshold= : Cases needed for a non-notifiable condition (default 3)}';

    protected $description = 'Flag barangays where several patients reported the same complaint';

    public function handle(
        OutbreakDetector $detector,
        NotificationService $notifications
    ): int {
        $windowDays = (int) ($this->option('days') ?: OutbreakDetector::DEFAULT_WINDOW_DAYS);
        $threshold = (int) ($this->option('threshold') ?: OutbreakDetector::DEFAULT_THRESHOLD);
        $dryRun = (bool) $this->option('dry-run');

        if (!Schema::hasTable('heatmap_alerts')) {
            $this->error('heatmap_alerts is missing; run the migrations first.');

            return self::FAILURE;
        }

        try {
            $clusters = $detector->detect($windowDays, $threshold);
        } catch (Throwable $e) {
            Log::error('[outbreak:detect] Detection failed', ['error' => $e->getMessage()]);
            $this->error('Detection failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($clusters === []) {
            $this->info("No clusters in the last {$windowDays} day(s).");

            return self::SUCCESS;
        }

        $raised = 0;
        $skipped = 0;

        foreach ($clusters as $cluster) {
            if ($detector->alreadyFlagged($cluster['barangay_id'], $cluster['condition'], $windowDays)) {
                $skipped++;

                $this->line(sprintf(
                    '  already open: %s — %s (%d cases)',
                    $cluster['barangay'],
                    $cluster['condition'],
                    $cluster['case_count']
                ));

                continue;
            }

            $message = $this->describe($cluster, $windowDays);

            $this->line(sprintf(
                '  %s %s — %s: %d cases in %d days',
                $cluster['notifiable'] ? '[NOTIFIABLE]' : '           ',
                $cluster['barangay'],
                $cluster['condition'],
                $cluster['case_count'],
                $windowDays
            ));

            if ($dryRun) {
                $raised++;
                continue;
            }

            try {
                DB::table('heatmap_alerts')->insert([
                    'barangay_id' => $cluster['barangay_id'],
                    'disease_type' => $cluster['condition'],
                    'alert_type' => 'outbreak_spike',
                    'severity' => $this->severity($cluster),
                    'trigger_message' => $message,
                    'case_count' => $cluster['case_count'],
                    // No baseline is used: see the note in OutbreakDetector on
                    // why a rolling average is the wrong tool at this volume.
                    'baseline_average' => 0,
                    'deviation_factor' => 0,
                    'is_resolved' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $notifications->notifyAdmins(
                    'outbreak_alert',
                    $cluster['notifiable']
                        ? "Possible {$cluster['condition']} cluster in {$cluster['barangay']}"
                        : "{$cluster['case_count']} cases of {$cluster['condition']} in {$cluster['barangay']}",
                    $message,
                    [
                        'barangay_id' => $cluster['barangay_id'],
                        'barangay' => $cluster['barangay'],
                        'condition' => $cluster['condition'],
                        'case_count' => $cluster['case_count'],
                        'window_days' => $windowDays,
                        'notifiable' => $cluster['notifiable'],
                        'screen' => 'heatmap',
                    ],
                    '/heatmap'
                );

                $raised++;
            } catch (Throwable $e) {
                // One failed alert must not stop the others: a cluster that
                // cannot be written is worth less than the ones that can.
                Log::error('[outbreak:detect] Could not raise alert', [
                    'barangay_id' => $cluster['barangay_id'],
                    'condition' => $cluster['condition'],
                    'error' => $e->getMessage(),
                ]);

                $this->warn('    could not raise this one: ' . $e->getMessage());
            }
        }

        $this->info(sprintf(
            '%s%d alert(s) raised, %d already open.',
            $dryRun ? '[dry run] ' : '',
            $raised,
            $skipped
        ));

        return self::SUCCESS;
    }

    /**
     * The sentence a staff member reads.
     *
     * Written to be acted on at a desk: what was seen, over what period, and
     * what it does and does not mean.
     *
     * @param array<string, mixed> $cluster
     */
    private function describe(array $cluster, int $windowDays): string
    {
        $lead = sprintf(
            '%d patients from %s reported %s in the last %d days (alert threshold %d).',
            $cluster['case_count'],
            $cluster['barangay'],
            mb_strtolower($cluster['condition']),
            $windowDays,
            $cluster['threshold']
        );

        $tail = $cluster['notifiable']
            ? ' This condition is notifiable, so two linked cases are enough to look into it. Verify the diagnoses and report to the PIDSR focal person if confirmed.'
            : ' This may be an outbreak, one household, or coincidence — it is a prompt to check the records, not a finding.';

        return $lead . $tail;
    }

    /** @param array<string, mixed> $cluster */
    private function severity(array $cluster): string
    {
        if ($cluster['notifiable']) {
            return $cluster['case_count'] >= 4 ? 'critical' : 'high';
        }

        return match (true) {
            $cluster['case_count'] >= 10 => 'critical',
            $cluster['case_count'] >= 6 => 'high',
            default => 'moderate',
        };
    }
}
