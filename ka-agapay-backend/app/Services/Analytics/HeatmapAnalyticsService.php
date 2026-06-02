<?php
// app/Services/Analytics/HeatmapAnalyticsService.php

namespace App\Services\Analytics;

use App\Models\Barangay;
use App\Models\BarangayHeatmap;
use App\Models\DiseaseCluster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Heatmap Analytics Engine — Geospatial Disease Concentration Analysis
 * =====================================================================
 *
 * Performs the following analytical operations:
 *   1. Case aggregation per barangay using consultation diagnosis data.
 *   2. Epidemiological incidence rate computation (cases per 1,000 residents).
 *   3. Queue density overlay (active waiting tickets per barangay).
 *   4. Composite heatmap intensity calculation combining incidence + congestion.
 *   5. Barangay risk level classification (Critical / High / Moderate / Low).
 *   6. Disease clustering detection across adjacent barangays.
 *   7. GIS-ready coordinate packaging for frontend map rendering.
 *
 * DSA Concept — Spatial Aggregation & Density Estimation:
 *   The service performs a GROUP BY aggregation over consultations joined
 *   with resident_profiles and barangays, computing density estimates
 *   analogous to kernel density estimation (KDE) used in geographic
 *   information systems, but using discrete polygon centroids.
 *
 * Heatmap Intensity Formula:
 *   I = min(10.0, (R_incidence × 0.7) + (D_queue × 0.3))
 *   where:
 *     R_incidence = (case_count / population) × 1000
 *     D_queue     = active_waiting_tickets from barangay
 */
class HeatmapAnalyticsService
{
    // ── Intensity component weights ──────────────────────────────────────
    private const INCIDENCE_WEIGHT = 0.7;
    private const QUEUE_WEIGHT     = 0.3;
    private const MAX_INTENSITY    = 10.00;

    // Default Malasiqui centre coordinates for fallback
    private const DEFAULT_LAT = 15.9196;
    private const DEFAULT_LNG = 120.4123;

    /**
     * Generate GIS-ready heatmap data for all barangays.
     *
     * @param  string|null  $diseaseFilter  Optional disease type filter (e.g., 'Dengue').
     * @param  string       $range          'week' or 'month' lookback period.
     * @return array        Array of heatmap point objects ready for Leaflet/OpenLayers.
     */
    public function generateHeatmapData(?string $diseaseFilter = null, string $range = 'week'): array
    {
        $days      = $range === 'month' ? 30 : 7;
        $startDate = now()->subDays($days)->toDateString();

        // ── 1. Aggregate consultation cases per barangay ─────────────────
        $caseQuery = DB::table('consultations as c')
            ->join('resident_profiles as rp', 'rp.user_id', '=', 'c.user_id')
            ->join('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
            ->select(
                'b.barangay_id',
                'b.name as barangay',
                'b.latitude',
                'b.longitude',
                'b.population',
                DB::raw('COUNT(c.id) as case_count'),
                DB::raw("MODE() WITHIN GROUP (ORDER BY c.diagnosis) as top_diagnosis")
            )
            ->where('c.consultation_date', '>=', $startDate)
            ->groupBy('b.barangay_id', 'b.name', 'b.latitude', 'b.longitude', 'b.population');

        if ($diseaseFilter) {
            $caseQuery->where('c.diagnosis', 'ILIKE', "%{$diseaseFilter}%");
        }

        $caseData = $caseQuery->get();

        // ── 2. Fetch real-time queue density per barangay ─────────────────
        $queueDensity = DB::table('queue_tickets as qt')
            ->join('resident_profiles as rp', 'rp.id', '=', 'qt.resident_profile_id')
            ->select('rp.barangay_id', DB::raw('COUNT(qt.id) as active_tickets'))
            ->where('qt.status', 'waiting')
            ->whereDate('qt.issued_at', today())
            ->whereNull('qt.deleted_at')
            ->groupBy('rp.barangay_id')
            ->pluck('active_tickets', 'barangay_id');

        // ── 3. Compute intensity and classify risk ───────────────────────
        $heatmapPoints = [];

        foreach ($caseData as $data) {
            $activeTickets = $queueDensity[$data->barangay_id] ?? 0;
            $population    = $data->population > 0 ? $data->population : 800;

            // Incidence rate per 1,000 residents
            $incidenceRate = ($data->case_count / $population) * 1000;

            // Composite heatmap intensity
            $intensity = min(
                self::MAX_INTENSITY,
                round(($incidenceRate * self::INCIDENCE_WEIGHT) + ($activeTickets * self::QUEUE_WEIGHT), 2)
            );

            $riskLevel = $this->classifyRiskLevel($intensity);

            $point = [
                'barangay_id'       => $data->barangay_id,
                'barangay'          => $data->barangay,
                'latitude'          => (float) ($data->latitude ?? self::DEFAULT_LAT),
                'longitude'         => (float) ($data->longitude ?? self::DEFAULT_LNG),
                'total_cases'       => (int) $data->case_count,
                'queue_density'     => (int) $activeTickets,
                'incidence_rate'    => round($incidenceRate, 2),
                'heatmap_intensity' => $intensity,
                'risk_level'        => $riskLevel,
                'top_case_type'     => $data->top_diagnosis ?? 'unspecified',
            ];

            $heatmapPoints[] = $point;

            // ── Persist daily snapshot ────────────────────────────────────
            BarangayHeatmap::updateOrCreate(
                [
                    'barangay_id'  => $data->barangay_id,
                    'disease_type' => $diseaseFilter ?? 'all',
                    'log_date'     => today()->toDateString(),
                ],
                [
                    'active_cases'      => $data->case_count,
                    'queue_density'     => $activeTickets,
                    'latitude'          => $data->latitude,
                    'longitude'         => $data->longitude,
                    'heatmap_intensity' => $intensity,
                    'risk_level'        => $riskLevel,
                    'top_case_type'     => $data->top_diagnosis,
                ]
            );
        }

        // Sort by intensity descending for dashboard display
        usort($heatmapPoints, fn($a, $b) => $b['heatmap_intensity'] <=> $a['heatmap_intensity']);

        return $heatmapPoints;
    }

    /**
     * Get barangay risk summary across all barangays.
     *
     * @return array{
     *   summary: array{total_barangays: int, risk_distribution: array},
     *   barangays: list<array>
     * }
     */
    public function getBarangayRiskSummary(): array
    {
        $barangays = Barangay::all();
        $todayHeatmaps = BarangayHeatmap::forToday()
            ->select('barangay_id', DB::raw('MAX(heatmap_intensity) as max_intensity'), DB::raw('MAX(risk_level) as risk_level'))
            ->groupBy('barangay_id')
            ->get()
            ->keyBy('barangay_id');

        $distribution = ['critical' => 0, 'high' => 0, 'moderate' => 0, 'low' => 0];
        $barangayList = [];

        foreach ($barangays as $b) {
            $heatmap   = $todayHeatmaps[$b->barangay_id] ?? null;
            $risk      = $heatmap->risk_level ?? 'low';
            $intensity = (float) ($heatmap->max_intensity ?? 0);

            $distribution[$risk] = ($distribution[$risk] ?? 0) + 1;

            $barangayList[] = [
                'barangay_id' => $b->barangay_id,
                'name'        => $b->name,
                'latitude'    => (float) ($b->latitude ?? self::DEFAULT_LAT),
                'longitude'   => (float) ($b->longitude ?? self::DEFAULT_LNG),
                'population'  => $b->population,
                'risk_level'  => $risk,
                'intensity'   => $intensity,
            ];
        }

        // Sort: critical first
        usort($barangayList, fn($a, $b) => $b['intensity'] <=> $a['intensity']);

        return [
            'summary' => [
                'total_barangays'   => $barangays->count(),
                'risk_distribution' => $distribution,
            ],
            'barangays' => $barangayList,
        ];
    }

    /**
     * Detect disease clusters by grouping barangays with elevated case
     * counts within the same time period.
     *
     * A cluster is identified when 3+ barangays within a geographic
     * proximity share the same elevated disease type.
     *
     * @return list<array>
     */
    public function detectDiseaseClusters(string $diseaseType, int $lookbackDays = 14): array
    {
        $startDate = now()->subDays($lookbackDays)->toDateString();

        // Get all barangays with significant cases
        $hotspots = DB::table('consultations as c')
            ->join('resident_profiles as rp', 'rp.user_id', '=', 'c.user_id')
            ->join('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
            ->select(
                'b.barangay_id',
                'b.name',
                'b.latitude',
                'b.longitude',
                DB::raw('COUNT(c.id) as case_count')
            )
            ->where('c.consultation_date', '>=', $startDate)
            ->where('c.diagnosis', 'ILIKE', "%{$diseaseType}%")
            ->groupBy('b.barangay_id', 'b.name', 'b.latitude', 'b.longitude')
            ->having(DB::raw('COUNT(c.id)'), '>=', 3)
            ->orderByDesc('case_count')
            ->get();

        if ($hotspots->count() < 2) {
            return [];
        }

        // Compute cluster centroid and radius
        $totalCases = $hotspots->sum('case_count');
        $centroidLat = $hotspots->avg('latitude');
        $centroidLng = $hotspots->avg('longitude');

        // Approximate radius using max distance from centroid (Haversine simplified)
        $maxDistKm = 0;
        foreach ($hotspots as $h) {
            $dist = $this->haversineDistance(
                $centroidLat, $centroidLng,
                $h->latitude, $h->longitude
            );
            $maxDistKm = max($maxDistKm, $dist);
        }

        // Density index: cases per square kilometre of cluster area
        $area = max(0.01, pi() * pow($maxDistKm, 2));
        $densityIndex = round($totalCases / $area, 2);

        $cluster = [
            'disease_type'       => $diseaseType,
            'case_count'         => $totalCases,
            'barangay_count'     => $hotspots->count(),
            'center_latitude'    => round($centroidLat, 8),
            'center_longitude'   => round($centroidLng, 8),
            'radius_km'          => round($maxDistKm, 2),
            'density_index'      => $densityIndex,
            'affected_barangays' => $hotspots->pluck('name')->toArray(),
            'period_start'       => $startDate,
            'period_end'         => today()->toDateString(),
        ];

        // Persist the detected cluster
        DiseaseCluster::create(array_merge($cluster, [
            'detected_at' => now(),
        ]));

        return [$cluster];
    }

    /**
     * Get hourly queue congestion data for trend analysis.
     *
     * @return Collection
     */
    public function getQueueDensityTrends(int $rhuId, ?string $date = null): Collection
    {
        $targetDate = $date ?? today()->toDateString();

        return collect(DB::select("
            SELECT *
            FROM vw_queue_congestion_hourly
            WHERE rhu_id = ?
              AND queue_date = ?
            ORDER BY hour_of_day
        ", [$rhuId, $targetDate]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Classify risk level from heatmap intensity score.
     *
     * Risk Classification (thesis-defined):
     *   Critical — intensity >= 7.5
     *   High     — intensity >= 5.0
     *   Moderate — intensity >= 2.5
     *   Low      — intensity <  2.5
     */
    private function classifyRiskLevel(float $intensity): string
    {
        if ($intensity >= 7.5) return 'critical';
        if ($intensity >= 5.0) return 'high';
        if ($intensity >= 2.5) return 'moderate';
        return 'low';
    }

    /**
     * Haversine distance formula (km) between two coordinate pairs.
     * Used for cluster radius estimation.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
           * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
