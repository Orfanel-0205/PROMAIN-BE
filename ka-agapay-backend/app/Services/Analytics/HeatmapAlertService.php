<?php
// app/Services/Analytics/HeatmapAlertService.php

namespace App\Services\Analytics;

use App\Models\BarangayHeatmap;
use App\Models\HeatmapAlert;
use App\Models\QueueTicket;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Heatmap Alert Engine — Outbreak Spike & Congestion Detection
 * =============================================================
 *
 * Responsibilities:
 *   1. Compare current case counts against historical baselines.
 *   2. Detect outbreak spikes (deviation_factor >= configured threshold).
 *   3. Detect abnormal queue congestion per RHU.
 *   4. Persist HeatmapAlert records for audit and dashboard display.
 *   5. Notify RHU admin staff through existing NotificationService.
 *
 * Detection Algorithm — Baseline Deviation:
 *   The baseline is the 4-week rolling average of case counts for the
 *   same disease in the same barangay.  A spike is flagged when:
 *
 *     deviation_factor = current_count / baseline_average >= SPIKE_THRESHOLD
 *
 *   This implements a simplified Relative Risk (RR) calculation consistent
 *   with WHO early-warning methodologies.
 *
 * DSA Concept — Sliding Window Aggregation:
 *   The 4-week baseline uses a fixed-size sliding window over
 *   barangay_heatmaps.log_date, avoiding full-table scans by relying
 *   on the (disease_type, log_date) composite index.
 *
 * OOP Pattern — Facade over NotificationService:
 *   Alert dispatch is fully delegated to the existing NotificationService,
 *   keeping this class focused on detection logic only.
 */
class HeatmapAlertService
{
    // ── Tunable thresholds ────────────────────────────────────────────────
    private const SPIKE_THRESHOLD           = 2.0;  // 2× baseline triggers alert
    private const CRITICAL_SPIKE_THRESHOLD  = 3.5;  // 3.5× baseline → critical severity
    private const CONGESTION_HIGH           = 30;   // tickets → high congestion
    private const CONGESTION_CRITICAL       = 50;   // tickets → critical congestion
    private const BASELINE_WINDOW_DAYS      = 28;   // 4-week rolling baseline

    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Run full outbreak spike detection across all barangay/disease pairs
     * that have been logged today.
     *
     * Intended to be called from a scheduled command or after each
     * HeatmapAnalyticsService::generateHeatmapData() run.
     *
     * @return list<HeatmapAlert>  Newly created alert records.
     */
    public function detectOutbreakSpikes(): array
    {
        $newAlerts = [];

        // Fetch today's logged heatmap rows grouped by barangay + disease
        $todayRows = BarangayHeatmap::forToday()
            ->with('barangay')
            ->get();

        foreach ($todayRows as $row) {
            $baseline = $this->computeBaseline(
                $row->barangay_id,
                $row->disease_type
            );

            // Skip if no historical data exists yet
            if ($baseline <= 0) {
                continue;
            }

            $deviationFactor = $row->active_cases / $baseline;

            if ($deviationFactor >= self::SPIKE_THRESHOLD) {
                $severity = $deviationFactor >= self::CRITICAL_SPIKE_THRESHOLD
                    ? 'critical'
                    : 'high';

                $alert = $this->createAlert(
                    barangayId:      $row->barangay_id,
                    diseaseType:     $row->disease_type,
                    alertType:       'outbreak_spike',
                    severity:        $severity,
                    caseCount:       $row->active_cases,
                    baseline:        $baseline,
                    deviationFactor: round($deviationFactor, 2),
                    message:         $this->buildSpikeMessage(
                                         $row->barangay->name ?? 'Unknown',
                                         $row->disease_type,
                                         $row->active_cases,
                                         $baseline,
                                         $deviationFactor
                                     )
                );

                if ($alert) {
                    $newAlerts[] = $alert;
                    $this->dispatchAdminNotification($alert);
                }
            }
        }

        return $newAlerts;
    }

    /**
     * Detect abnormal queue congestion for a specific RHU facility.
     *
     * Congestion is evaluated against the current waiting ticket count.
     * Called by QueueController or a periodic health-check command.
     *
     * @param  int  $rhuId
     * @return HeatmapAlert|null
     */
    public function detectQueueCongestion(int $rhuId): ?HeatmapAlert
    {
        $waitingCount = QueueTicket::where('rhu_id', $rhuId)
            ->where('status', 'waiting')
            ->whereDate('issued_at', today())
            ->whereNull('deleted_at')
            ->count();

        if ($waitingCount < self::CONGESTION_HIGH) {
            return null;
        }

        $severity = $waitingCount >= self::CONGESTION_CRITICAL
            ? 'critical'
            : 'high';

        // Guard: don't duplicate congestion alerts within the same day
        $existing = HeatmapAlert::where('alert_type', 'congestion_alert')
            ->where('is_resolved', false)
            ->whereDate('created_at', today())
            ->whereHas('barangay') // any barangay-level congestion alert
            ->first();

        if ($existing) {
            return null;
        }

        $message = sprintf(
            'RHU %d queue congestion alert: %d patients currently waiting. '
            . 'Action required to reduce patient backlog.',
            $rhuId,
            $waitingCount
        );

        // Use a null barangay_id workaround: store under Poblacion (the RHU barangay)
        $rhuBarangayId = DB::table('barangays')
            ->where('name', 'Poblacion')
            ->value('barangay_id') ?? 1;

        $alert = $this->createAlert(
            barangayId:      $rhuBarangayId,
            diseaseType:     'queue_congestion',
            alertType:       'congestion_alert',
            severity:        $severity,
            caseCount:       $waitingCount,
            baseline:        self::CONGESTION_HIGH,
            deviationFactor: round($waitingCount / self::CONGESTION_HIGH, 2),
            message:         $message
        );

        if ($alert) {
            $this->dispatchAdminNotification($alert);
        }

        return $alert;
    }

    /**
     * Fetch all currently active (unresolved) alerts, optionally filtered.
     *
     * @param  string|null  $severity   Filter: 'low' | 'moderate' | 'high' | 'critical'
     * @param  string|null  $alertType  Filter: 'outbreak_spike' | 'congestion_alert'
     * @return Collection<HeatmapAlert>
     */
    public function getActiveAlerts(
        ?string $severity  = null,
        ?string $alertType = null
    ): Collection {
        $query = HeatmapAlert::active()
            ->with('barangay:barangay_id,name,latitude,longitude')
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 1
                    WHEN 'high'     THEN 2
                    WHEN 'moderate' THEN 3
                    ELSE 4
                END
            ")
            ->orderByDesc('created_at');

        if ($severity) {
            $query->bySeverity($severity);
        }

        if ($alertType) {
            $query->ofType($alertType);
        }

        return $query->get();
    }

    /**
     * Resolve an alert and record who resolved it.
     *
     * @param  int      $alertId
     * @param  int      $resolvedByUserId
     * @param  string   $notes
     * @return bool
     */
    public function resolveAlert(int $alertId, int $resolvedByUserId, string $notes = ''): bool
    {
        $alert = HeatmapAlert::find($alertId);

        if (!$alert || $alert->is_resolved) {
            return false;
        }

        $alert->resolve($resolvedByUserId, $notes);

        Log::info('[HeatmapAlertService] Alert resolved', [
            'alert_id'   => $alertId,
            'resolved_by'=> $resolvedByUserId,
        ]);

        return true;
    }

    /**
     * Return a summary of alert counts grouped by severity for the dashboard.
     *
     * @return array{total: int, critical: int, high: int, moderate: int, low: int}
     */
    public function getAlertSummary(): array
    {
        $counts = HeatmapAlert::active()
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity');

        return [
            'total'    => $counts->sum(),
            'critical' => (int) ($counts['critical'] ?? 0),
            'high'     => (int) ($counts['high']     ?? 0),
            'moderate' => (int) ($counts['moderate'] ?? 0),
            'low'      => (int) ($counts['low']      ?? 0),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Compute the 4-week rolling average case count for a disease
     * in a given barangay, excluding today.
     *
     * Uses the barangay_heatmaps table rather than raw consultations
     * so the baseline reflects already-normalised daily snapshots.
     */
    private function computeBaseline(int $barangayId, string $diseaseType): float
    {
        $avg = DB::table('barangay_heatmaps')
            ->where('barangay_id', $barangayId)
            ->where('disease_type', $diseaseType)
            ->where('log_date', '<', today())
            ->where('log_date', '>=', now()->subDays(self::BASELINE_WINDOW_DAYS)->toDateString())
            ->avg('active_cases');

        return (float) ($avg ?? 0);
    }

    /**
     * Persist a new HeatmapAlert, deduplicating against active alerts
     * for the same barangay + disease on the same day.
     */
    private function createAlert(
        int    $barangayId,
        string $diseaseType,
        string $alertType,
        string $severity,
        int    $caseCount,
        float  $baseline,
        float  $deviationFactor,
        string $message
    ): ?HeatmapAlert {
        // Deduplication guard: one active alert per barangay + disease per day
        $duplicate = HeatmapAlert::where('barangay_id', $barangayId)
            ->where('disease_type', $diseaseType)
            ->where('alert_type', $alertType)
            ->where('is_resolved', false)
            ->whereDate('created_at', today())
            ->exists();

        if ($duplicate) {
            return null;
        }

        return HeatmapAlert::create([
            'barangay_id'      => $barangayId,
            'disease_type'     => $diseaseType,
            'alert_type'       => $alertType,
            'severity'         => $severity,
            'trigger_message'  => $message,
            'case_count'       => $caseCount,
            'baseline_average' => $baseline,
            'deviation_factor' => $deviationFactor,
            'is_resolved'      => false,
        ]);
    }

    /**
     * Build a human-readable spike message for the alert record.
     */
    private function buildSpikeMessage(
        string $barangayName,
        string $disease,
        int    $currentCount,
        float  $baseline,
        float  $factor
    ): string {
        return sprintf(
            'Outbreak spike detected in %s: %d %s cases reported today (%.1fx above '
            . 'the %.1f-case 4-week baseline). Immediate assessment recommended.',
            $barangayName,
            $currentCount,
            $disease,
            $factor,
            $baseline
        );
    }

    /**
     * Dispatch an in-app notification to RHU admin users.
     * Delegates entirely to the existing NotificationService.
     */
    private function dispatchAdminNotification(HeatmapAlert $alert): void
    {
        try {
            $this->notificationService->notifyAdmins(
                title:   ucfirst($alert->severity) . ' Health Alert — ' . $alert->disease_type,
                message: $alert->trigger_message,
                data: [
                    'alert_id'    => $alert->id,
                    'alert_type'  => $alert->alert_type,
                    'severity'    => $alert->severity,
                    'barangay_id' => $alert->barangay_id,
                ]
            );
        } catch (\Throwable $e) {
            // Notification failure must never break the detection pipeline
            Log::warning('[HeatmapAlertService] Admin notification failed', [
                'alert_id' => $alert->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}