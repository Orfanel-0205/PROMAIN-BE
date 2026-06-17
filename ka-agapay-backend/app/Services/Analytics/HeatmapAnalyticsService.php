<?php
// app/Services/Analytics/HeatmapAnalyticsService.php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HeatmapAnalyticsService
{
    private const DEFAULT_LAT = 15.9196;
    private const DEFAULT_LNG = 120.4123;
    private const DEFAULT_POPULATION = 800;

    public function generateHeatmapData(?string $diseaseFilter = null, string $range = 'week'): array
    {
        if (!Schema::hasTable('consultations') || !Schema::hasTable('barangays')) {
            return [];
        }

        $days = $range === 'month' ? 30 : 7;

        $startDate = now()->subDays($days)->startOfDay();
        $endDate = now()->endOfDay();

        $dateColumn = Schema::hasColumn('consultations', 'consultation_date')
            ? 'consultation_date'
            : 'created_at';

        $rows = $this->getConsultationRows(
            $startDate,
            $endDate,
            $dateColumn,
            $diseaseFilter
        );

        if ($rows->isEmpty()) {
            return [];
        }

        $queueDensity = $this->getQueueDensityByBarangay();

        $points = $rows
            ->groupBy('barangay_id')
            ->map(function (Collection $items) use ($queueDensity) {
                $first = $items->first();

                $barangayId = (int) ($first->barangay_id ?? 0);
                $caseCount = $items->count();
                $queueCount = (int) ($queueDensity[$barangayId] ?? 0);

                $population = (int) ($first->population ?? self::DEFAULT_POPULATION);

                if ($population <= 0) {
                    $population = self::DEFAULT_POPULATION;
                }

                $caseCounts = $items
                    ->pluck('case_type')
                    ->map(fn ($value) => trim((string) $value) ?: 'Unspecified')
                    ->countBy()
                    ->sortDesc();

                $topCaseType = (string) ($caseCounts->keys()->first() ?? 'Unspecified');

                $incidenceRate = ($caseCount / $population) * 1000;

                $intensity = min(
                    10,
                    round(($caseCount * 1.2) + ($incidenceRate * 0.5) + ($queueCount * 0.3), 2)
                );

                return [
                    'barangay_id' => $barangayId,
                    'barangay' => $first->barangay ?? 'Unspecified',
                    'latitude' => (float) ($first->latitude ?? self::DEFAULT_LAT),
                    'longitude' => (float) ($first->longitude ?? self::DEFAULT_LNG),
                    'population' => $population,
                    'total_cases' => $caseCount,
                    'queue_density' => $queueCount,
                    'incidence_rate' => round($incidenceRate, 2),
                    'heatmap_intensity' => $intensity,
                    'risk_level' => $this->classifyRiskLevel($intensity),
                    'top_case_type' => $topCaseType,
                ];
            })
            ->sortByDesc('heatmap_intensity')
            ->values()
            ->all();

        return $points;
    }

    private function getConsultationRows($startDate, $endDate, string $dateColumn, ?string $diseaseFilter): Collection
    {
        $hasResidentProfiles = Schema::hasTable('resident_profiles')
            && Schema::hasColumn('resident_profiles', 'user_id')
            && Schema::hasColumn('resident_profiles', 'barangay_id');

        $hasUsers = Schema::hasTable('users');
        $hasUserBarangayId = $hasUsers && Schema::hasColumn('users', 'barangay_id');

        if (!$hasResidentProfiles && !$hasUserBarangayId) {
            return collect();
        }

        $diagnosisColumn = Schema::hasColumn('consultations', 'diagnosis')
            ? 'c.diagnosis'
            : null;

        $complaintColumn = Schema::hasColumn('consultations', 'chief_complaint')
            ? 'c.chief_complaint'
            : null;

        $assessmentColumn = Schema::hasColumn('consultations', 'assessment')
            ? 'c.assessment'
            : null;

        $caseTypeParts = [];

        if ($diagnosisColumn) {
            $caseTypeParts[] = "NULLIF({$diagnosisColumn}, '')";
        }

        if ($complaintColumn) {
            $caseTypeParts[] = "NULLIF({$complaintColumn}, '')";
        }

        if ($assessmentColumn) {
            $caseTypeParts[] = "NULLIF({$assessmentColumn}, '')";
        }

        $caseTypeExpr = count($caseTypeParts) > 0
            ? 'COALESCE(' . implode(', ', $caseTypeParts) . ", 'Unspecified')"
            : "'Unspecified'";

        $latExpr = Schema::hasColumn('barangays', 'latitude')
            ? 'b.latitude'
            : (string) self::DEFAULT_LAT;

        $lngExpr = Schema::hasColumn('barangays', 'longitude')
            ? 'b.longitude'
            : (string) self::DEFAULT_LNG;

        $populationExpr = Schema::hasColumn('barangays', 'population')
            ? 'b.population'
            : (string) self::DEFAULT_POPULATION;

        $query = DB::table('consultations as c');

        if ($hasResidentProfiles) {
            $query
                ->join('resident_profiles as rp', 'rp.user_id', '=', 'c.user_id')
                ->join('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id');
        } else {
            $query
                ->join('users as u', 'u.user_id', '=', 'c.user_id')
                ->join('barangays as b', 'b.barangay_id', '=', 'u.barangay_id');
        }

        $likeOperator = DB::connection()->getDriverName() === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';

        return $query
            ->selectRaw('b.barangay_id')
            ->selectRaw('b.name as barangay')
            ->selectRaw("{$latExpr} as latitude")
            ->selectRaw("{$lngExpr} as longitude")
            ->selectRaw("{$populationExpr} as population")
            ->selectRaw("{$caseTypeExpr} as case_type")
            ->whereBetween("c.{$dateColumn}", [$startDate, $endDate])
            ->when(trim((string) $diseaseFilter) !== '', function ($q) use (
                $diseaseFilter,
                $diagnosisColumn,
                $complaintColumn,
                $assessmentColumn,
                $likeOperator
            ) {
                $search = trim((string) $diseaseFilter);

                $q->where(function ($inner) use (
                    $search,
                    $diagnosisColumn,
                    $complaintColumn,
                    $assessmentColumn,
                    $likeOperator
                ) {
                    if ($diagnosisColumn) {
                        $inner->orWhere($diagnosisColumn, $likeOperator, "%{$search}%");
                    }

                    if ($complaintColumn) {
                        $inner->orWhere($complaintColumn, $likeOperator, "%{$search}%");
                    }

                    if ($assessmentColumn) {
                        $inner->orWhere($assessmentColumn, $likeOperator, "%{$search}%");
                    }
                });
            })
            ->get();
    }

    private function getQueueDensityByBarangay(): array
    {
        if (!Schema::hasTable('queue_tickets')) {
            return [];
        }

        if (
            !Schema::hasTable('resident_profiles') ||
            !Schema::hasColumn('queue_tickets', 'resident_profile_id') ||
            !Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            return [];
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'issued_at')
            ? 'issued_at'
            : 'created_at';

        return DB::table('queue_tickets as qt')
            ->join('resident_profiles as rp', 'rp.id', '=', 'qt.resident_profile_id')
            ->selectRaw('rp.barangay_id')
            ->selectRaw('COUNT(qt.id) as active_tickets')
            ->whereIn('qt.status', ['waiting', 'called', 'in_service'])
            ->whereDate("qt.{$dateColumn}", today())
            ->when(Schema::hasColumn('queue_tickets', 'deleted_at'), function ($q) {
                $q->whereNull('qt.deleted_at');
            })
            ->groupBy('rp.barangay_id')
            ->pluck('active_tickets', 'barangay_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function classifyRiskLevel(float $intensity): string
    {
        if ($intensity >= 7.5) {
            return 'critical';
        }

        if ($intensity >= 5.0) {
            return 'high';
        }

        if ($intensity >= 2.5) {
            return 'moderate';
        }

        return 'low';
    }
}