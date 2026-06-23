<?php
// database/migrations/2026_06_23_000009_backfill_completed_consultation_heatmap_freshness.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('consultations')) {
            return;
        }

        foreach (['status', 'heatmap_posted_at', 'heatmap_signal_expires_at'] as $column) {
            if (!Schema::hasColumn('consultations', $column)) {
                return;
            }
        }

        DB::table('consultations')
            ->whereRaw("LOWER(COALESCE(status, '')) = 'completed'")
            ->whereNull('heatmap_posted_at')
            ->select(['id', 'completed_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $postedAt = $this->safeDateTime($row->completed_at ?? null)
                        ?? $this->safeDateTime($row->updated_at ?? null)
                        ?? now();

                    DB::table('consultations')
                        ->where('id', $row->id)
                        ->whereNull('heatmap_posted_at')
                        ->update([
                            'heatmap_posted_at' => $postedAt,
                            'heatmap_signal_expires_at' => (clone $postedAt)->addHours(3),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally no destructive rollback.
        // These fields are derived freshness timestamps for completed records only.
    }

    private function safeDateTime(mixed $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
};
