<?php
// app/Services/Analytics/HeatmapAnalyticsService.php

namespace App\Services\Analytics;

use App\Support\Rhu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HeatmapAnalyticsService
{
    private const DEFAULT_LAT = 15.9202;
    private const DEFAULT_LNG = 120.4145;
    private const DEFAULT_POPULATION = 800;

    public function generateHeatmapData(
    ?string $diseaseFilter = null,
    string $range = 'week',
    bool $activeOnly = false
): array {
    if (!Schema::hasTable('barangays')) {
        return [];
    }

    $days = $range === 'month' ? 30 : 7;
    $from = now()->subDays($days)->startOfDay();
    $to = now()->endOfDay();

    $barangays = $this->barangays();

    $signals = collect()
        ->merge($this->sourceSignals('consultations', 'c', 'consultation', ['consultation_date', 'created_at'], [
            'chief_complaint',
            'diagnosis',
            'assessment',
            'subjective',
            'objective',
            'notes',
            'treatment',
        ], $from, $to, $diseaseFilter))
        ->merge($this->sourceSignals('appointments', 'a', 'appointment', ['appointment_date', 'scheduled_at', 'created_at'], [
            'reason',
            'symptoms',
            'purpose',
            'notes',
            'description',
        ], $from, $to, $diseaseFilter))
        ->merge($this->sourceSignals('telemedicine_requests', 'tr', 'telemedicine', ['created_at', 'screened_at'], [
            'chief_complaint',
            'symptoms',
            'additional_notes',
            'screening_notes',
        ], $from, $to, $diseaseFilter))
        ->merge($this->chatSignals($from, $to, $diseaseFilter, $barangays));

    $queueDensity = $this->queueDensityByBarangay();

    $signalsByBarangay = $signals
        ->filter(fn ($row) => (int) ($row->barangay_id ?? 0) > 0)
        ->groupBy(fn ($row) => (int) $row->barangay_id)
        ->map(function (Collection $items) {
            $cases = $items
                ->pluck('case_type')
                ->map(fn ($value) => $this->classifyComplaint((string) $value))
                ->filter()
                ->countBy()
                ->sortDesc();

            $sources = $items
                ->pluck('source')
                ->map(fn ($value) => trim((string) $value) ?: 'unknown')
                ->countBy()
                ->sortDesc();

            return [
                'total_cases' => $items->count(),
                'top_case_type' => (string) ($cases->keys()->first() ?? 'Unspecified'),
                'source_breakdown' => $sources->all(),
            ];
        });

    $points = $barangays
        ->map(function ($barangay) use ($signalsByBarangay, $queueDensity, $diseaseFilter, $activeOnly) {
            $barangayId = (int) $barangay->barangay_id;

            $data = $signalsByBarangay->get($barangayId, [
                'total_cases' => 0,
                'top_case_type' => 'Unspecified',
                'source_breakdown' => [],
            ]);

            $caseCount = (int) ($data['total_cases'] ?? 0);

            /*
             * Real-life rule:
             * Queue pressure should support case interpretation,
             * but it must not create a map pin if there is no case signal.
             */
            $queueCount = $caseCount > 0
                ? (int) ($queueDensity[$barangayId] ?? 0)
                : 0;

            if ($activeOnly && $caseCount <= 0) {
                return null;
            }

            $hasValidLat = $this->validLat($barangay->latitude);
            $hasValidLng = $this->validLng($barangay->longitude);

            /*
             * Do not use default/fallback coordinates for GIS heatmap pins.
             * Returning fake coordinates causes wrong map alerts.
             */
            if (!$hasValidLat || !$hasValidLng) {
                return null;
            }

            $population = max(1, (int) ($barangay->population ?? self::DEFAULT_POPULATION));
            $incidence = round(($caseCount / $population) * 1000, 2);

            $intensity = min(10, round(
                ($caseCount * 1.8) +
                ($incidence * 0.7) +
                ($queueCount * 0.7),
                2
            ));

            $riskScore = min(100, round($intensity * 10, 2));
            $riskLevel = $this->riskLevel($intensity);

            $topCase = trim((string) ($data['top_case_type'] ?? 'Unspecified')) ?: 'Unspecified';

            $rhuId = Rhu::normalizeRhuId((int) ($barangay->rhu_id ?? 0) ?: null);

            $point = [
                'barangay_id' => $barangayId,
                'barangay' => $barangay->barangay,
                'rhu_id' => $rhuId,
                'rhu_label' => Rhu::rhuLabel($rhuId),
                'latitude' => (float) $barangay->latitude,
                'longitude' => (float) $barangay->longitude,
                'coordinate_source' => 'database',
                'population' => $population,
                'total_cases' => $caseCount,
                'queue_density' => $queueCount,
                'incidence_rate' => $incidence,
                'heatmap_intensity' => $intensity,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
                'top_case_type' => $topCase,
                'top_complaint' => $topCase,
                'source_breakdown' => $data['source_breakdown'] ?? [],
            ];

            /*
             * Storage-safe:
             * Save only barangays with real case signals.
             * This prevents barangay_heatmaps from filling with 73 zero rows daily.
             */
            if ($caseCount > 0) {
                $this->storeSnapshot($point, $diseaseFilter);
                $this->notifyIfRiskNeedsAction($point, $diseaseFilter);
            }

            return $point;
        })
        ->filter()
        ->sortByDesc('risk_score')
        ->sortByDesc('total_cases')
        ->values()
        ->all();

    return $points;
}

    private function barangays(): Collection
    {
        $lat = Schema::hasColumn('barangays', 'latitude') ? 'latitude' : DB::raw(self::DEFAULT_LAT . ' as latitude');
        $lng = Schema::hasColumn('barangays', 'longitude') ? 'longitude' : DB::raw(self::DEFAULT_LNG . ' as longitude');
        $pop = Schema::hasColumn('barangays', 'population') ? 'population' : DB::raw(self::DEFAULT_POPULATION . ' as population');
        // RHU facility mapping (1 = RHU 1, 2 = RHU 2 / Don Pedro). Preserves the
        // existing barangays.rhu_id work; degrades to NULL if not migrated yet.
        $rhu = Schema::hasColumn('barangays', 'rhu_id') ? 'rhu_id' : DB::raw('NULL as rhu_id');

        return DB::table('barangays')
            ->select('barangay_id')
            ->selectRaw('TRIM(name) as barangay')
            ->addSelect($lat)
            ->addSelect($lng)
            ->addSelect($pop)
            ->addSelect($rhu)
            ->orderBy('name')
            ->get();
    }

    private function sourceSignals(
        string $table,
        string $alias,
        string $source,
        array $dateCandidates,
        array $textCandidates,
        Carbon $from,
        Carbon $to,
        ?string $diseaseFilter
    ): Collection {
        if (!Schema::hasTable($table)) {
            return collect();
        }

        $dateColumn = $this->firstColumn($table, $dateCandidates);
        $textColumns = array_values(array_filter(
            $textCandidates,
            fn ($column) => Schema::hasColumn($table, $column)
        ));

        if (!$dateColumn || count($textColumns) === 0) {
            return collect();
        }

        $query = DB::table("{$table} as {$alias}");

        $barangayExpr = $this->attachBarangayMapping($query, $table, $alias);

        if (!$barangayExpr) {
            return collect();
        }

        $textExpr = $this->concatTextExpression($alias, $textColumns);
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query
            ->selectRaw("{$barangayExpr} as barangay_id")
            ->selectRaw("{$textExpr} as case_type")
            ->selectRaw("'{$source}' as source")
            ->whereBetween("{$alias}.{$dateColumn}", [$from, $to])
            ->when(trim((string) $diseaseFilter) !== '', function ($q) use ($textExpr, $like, $diseaseFilter) {
                $q->whereRaw("{$textExpr} {$like} ?", ['%' . trim((string) $diseaseFilter) . '%']);
            })
            ->get()
            ->map(function ($row) {
                $row->case_type = $this->cleanText((string) ($row->case_type ?? ''));
                return $row;
            })
            ->filter(fn ($row) => (int) ($row->barangay_id ?? 0) > 0 && trim((string) $row->case_type) !== '')
            ->values();
    }

    private function attachBarangayMapping($query, string $table, string $alias): ?string
    {
        $parts = [];

        if (Schema::hasColumn($table, 'barangay_id')) {
            $parts[] = "{$alias}.barangay_id";
        }

        if (
            Schema::hasColumn($table, 'resident_profile_id') &&
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            $query->leftJoin("resident_profiles as rp_{$alias}", "rp_{$alias}.id", '=', "{$alias}.resident_profile_id");
            $parts[] = "rp_{$alias}.barangay_id";
        }

        if (
            Schema::hasColumn($table, 'user_id') &&
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            $query->leftJoin("resident_profiles as rpu_{$alias}", "rpu_{$alias}.user_id", '=', "{$alias}.user_id");
            $parts[] = "rpu_{$alias}.barangay_id";
        }

        if (
            Schema::hasColumn($table, 'requested_by') &&
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            $query->leftJoin("resident_profiles as rpr_{$alias}", "rpr_{$alias}.user_id", '=', "{$alias}.requested_by");
            $parts[] = "rpr_{$alias}.barangay_id";
        }

        if (
            Schema::hasColumn($table, 'user_id') &&
            Schema::hasTable('users') &&
            Schema::hasColumn('users', 'user_id') &&
            Schema::hasColumn('users', 'barangay_id')
        ) {
            $query->leftJoin("users as u_{$alias}", "u_{$alias}.user_id", '=', "{$alias}.user_id");
            $parts[] = "u_{$alias}.barangay_id";
        }

        if (count($parts) === 0) {
            return null;
        }

        return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function chatSignals(Carbon $from, Carbon $to, ?string $diseaseFilter, Collection $barangays): Collection
    {
        $table = Schema::hasTable('chat_messages')
            ? 'chat_messages'
            : (Schema::hasTable('chat_logs') ? 'chat_logs' : null);

        if (!$table) {
            return collect();
        }

        $dateColumn = $this->firstColumn($table, ['created_at', 'sent_at', 'updated_at']);
        $contentColumn = $this->firstColumn($table, ['content', 'message', 'prompt', 'text', 'body', 'question']);
        $roleColumn = $this->firstColumn($table, ['role', 'sender', 'sender_type']);

        if (!$dateColumn || !$contentColumn) {
            return collect();
        }

        $query = DB::table("{$table} as cm");
        $barangayExpr = $this->attachBarangayMapping($query, $table, 'cm') ?: 'NULL';

        $textExpr = $this->safeText('cm', $contentColumn);
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query
            ->selectRaw("{$barangayExpr} as barangay_id")
            ->selectRaw("{$textExpr} as case_type")
            ->selectRaw("'chatbot' as source")
            ->whereBetween("cm.{$dateColumn}", [$from, $to])
            ->when($roleColumn, function ($q) use ($roleColumn) {
                $q->where(function ($inner) use ($roleColumn) {
                    $inner->whereNull("cm.{$roleColumn}")
                        ->orWhereNotIn("cm.{$roleColumn}", ['assistant', 'bot', 'system', 'ai']);
                });
            })
            ->when(trim((string) $diseaseFilter) !== '', function ($q) use ($textExpr, $like, $diseaseFilter) {
                $q->whereRaw("{$textExpr} {$like} ?", ['%' . trim((string) $diseaseFilter) . '%']);
            })
            ->when(trim((string) $diseaseFilter) === '', function ($q) use ($textExpr, $like) {
                $keywords = ['fever', 'lagnat', 'cough', 'ubo', 'migraine', 'sakit', 'dengue', 'diarrhea', 'sipon', 'headache', 'pneumonia'];
                $q->where(function ($inner) use ($keywords, $textExpr, $like) {
                    foreach ($keywords as $keyword) {
                        $inner->orWhereRaw("{$textExpr} {$like} ?", ["%{$keyword}%"]);
                    }
                });
            })
            ->get()
            ->map(function ($row) use ($barangays) {
                $text = $this->cleanText((string) ($row->case_type ?? ''));
                $barangayId = (int) ($row->barangay_id ?? 0);

                if ($barangayId <= 0) {
                    $barangayId = $this->barangayMentionedInText($text, $barangays);
                }

                $row->barangay_id = $barangayId;
                $row->case_type = $text;

                return $row;
            })
            ->filter(fn ($row) => (int) ($row->barangay_id ?? 0) > 0 && trim((string) $row->case_type) !== '')
            ->values();
    }

    private function queueDensityByBarangay(): array
    {
        if (!Schema::hasTable('queue_tickets')) {
            return [];
        }

        $dateColumn = Schema::hasColumn('queue_tickets', 'issued_at') ? 'issued_at' : 'created_at';

        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('queue_tickets', 'resident_profile_id') &&
            Schema::hasColumn('resident_profiles', 'id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            return DB::table('queue_tickets as qt')
                ->join('resident_profiles as rp', 'rp.id', '=', 'qt.resident_profile_id')
                ->selectRaw('rp.barangay_id, COUNT(qt.id) as total')
                ->whereIn('qt.status', ['waiting', 'called', 'in_service'])
                ->whereDate("qt.{$dateColumn}", today())
                ->when(Schema::hasColumn('queue_tickets', 'deleted_at'), fn ($q) => $q->whereNull('qt.deleted_at'))
                ->groupBy('rp.barangay_id')
                ->pluck('total', 'barangay_id')
                ->map(fn ($value) => (int) $value)
                ->all();
        }

        return [];
    }

    private function storeSnapshot(array $point, ?string $diseaseFilter): void
    {if ((int) ($point['total_cases'] ?? 0) <= 0) {
    return;
}
        if (!Schema::hasTable('barangay_heatmaps')) {
            return;
        }

        $disease = trim((string) $diseaseFilter) !== ''
            ? trim((string) $diseaseFilter)
            : ($point['top_case_type'] ?: 'General Health Signal');

        DB::table('barangay_heatmaps')->updateOrInsert(
            [
                'barangay_id' => $point['barangay_id'],
                'disease_type' => $disease,
                'log_date' => today()->toDateString(),
            ],
            [
                'active_cases' => $point['total_cases'],
                'queue_density' => $point['queue_density'],
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'heatmap_intensity' => $point['heatmap_intensity'],
                'risk_level' => $point['risk_level'],
                'top_case_type' => $point['top_case_type'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function notifyIfRiskNeedsAction(array $point, ?string $diseaseFilter): void
    {
        if (!Schema::hasTable('heatmap_alerts')) {
            return;
        }

        if (!in_array($point['risk_level'], ['high', 'critical'], true)) {
            return;
        }

       if ((int) ($point['total_cases'] ?? 0) <= 0) {
    return;
}
        $disease = trim((string) $diseaseFilter) !== ''
            ? trim((string) $diseaseFilter)
            : ($point['top_case_type'] ?: 'General Health Signal');

        $exists = DB::table('heatmap_alerts')
            ->where('barangay_id', $point['barangay_id'])
            ->where('disease_type', $disease)
            ->where('alert_type', 'high_risk_zone')
            ->where('is_resolved', false)
            ->whereDate('created_at', today())
            ->exists();

        if ($exists) {
            return;
        }

        $message = "{$point['barangay']} is marked {$point['risk_level']} risk for {$disease}. Cases/signals: {$point['total_cases']}, queue pressure: {$point['queue_density']}.";

        $alertId = DB::table('heatmap_alerts')->insertGetId([
            'barangay_id' => $point['barangay_id'],
            'disease_type' => $disease,
            'alert_type' => 'high_risk_zone',
            'severity' => $point['risk_level'],
            'trigger_message' => $message,
            'case_count' => $point['total_cases'],
            'baseline_average' => 0,
            'deviation_factor' => $point['risk_score'],
            'is_resolved' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(\App\Services\Notification\NotificationService::class)->notifyAdmins(
                'heatmap_alert',
                'Barangay health risk alert',
                $message,
                [
                    'related_type' => 'analytics_heatmap',
                    'related_id' => $alertId,
                    'barangay_id' => $point['barangay_id'],
                    'risk_level' => $point['risk_level'],
                ],
                '/analytics/heatmap'
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function firstColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function concatTextExpression(string $alias, array $columns): string
    {
        $parts = array_map(fn ($column) => $this->safeText($alias, $column), $columns);
        return "NULLIF(TRIM(CONCAT_WS(' ', " . implode(', ', $parts) . ")), '')";
    }

    private function safeText(string $alias, string $column): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? "CAST({$alias}.{$column} AS TEXT)"
            : "CAST({$alias}.{$column} AS CHAR)";
    }

    private function cleanText(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim(strip_tags($value))) ?? '';
        return mb_strlen($value) > 180 ? mb_substr($value, 0, 180) . '...' : $value;
    }

    private function classifyComplaint(string $text): string
    {
        $value = mb_strtolower($text);

        if (str_contains($value, 'dengue')) return 'Possible Dengue';
        if (str_contains($value, 'fever') && str_contains($value, 'cough')) return 'Fever and cough';
        if (str_contains($value, 'lagnat') && str_contains($value, 'ubo')) return 'Lagnat at ubo';
        if (str_contains($value, 'migraine')) return 'Fever and migraine';
        if (str_contains($value, 'headache') || str_contains($value, 'sakit ang ulo')) return 'Headache';
        if (str_contains($value, 'sipon')) return 'Ubo at sipon';
        if (str_contains($value, 'diarrhea') || str_contains($value, 'pagtatae')) return 'Diarrhea';
        if (str_contains($value, 'fever') || str_contains($value, 'lagnat')) return 'Fever';
        if (str_contains($value, 'cough') || str_contains($value, 'ubo')) return 'Cough';
        if (str_contains($value, 'throat') || str_contains($value, 'lalamunan')) return 'Sore throat';

        return $this->cleanText($text) ?: 'Unspecified';
    }

    private function barangayMentionedInText(string $text, Collection $barangays): int
    {
        $needle = mb_strtolower($text);

        foreach ($barangays as $barangay) {
            $name = mb_strtolower((string) $barangay->barangay);

            if ($name !== '' && str_contains($needle, $name)) {
                return (int) $barangay->barangay_id;
            }
        }

        return 0;
    }

    private function riskLevel(float $intensity): string
    {
        if ($intensity >= 8) return 'critical';
        if ($intensity >= 5.5) return 'high';
        if ($intensity >= 2.5) return 'moderate';
        return 'low';
    }

    private function validLat($value): bool
    {
        $n = (float) $value;
        return $n >= -90 && $n <= 90 && $n !== 0.0;
    }

    private function validLng($value): bool
    {
        $n = (float) $value;
        return $n >= -180 && $n <= 180 && $n !== 0.0;
    }
}