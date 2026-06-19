<?php
// database/seeders/SyncHeatmapCoordinatesSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Syncs barangay_heatmaps copied coordinates with the latest barangays table.
 *
 * Run after BarangayCoordinatesSeeder:
 * php artisan db:seed --class=SyncHeatmapCoordinatesSeeder
 */
class SyncHeatmapCoordinatesSeeder extends Seeder
{
    private function validCoordinate(float $lat, float $lng): bool
    {
        return $lat >= -90 &&
            $lat <= 90 &&
            $lng >= -180 &&
            $lng <= 180 &&
            $lat !== 0.0 &&
            $lng !== 0.0;
    }

    public function run(): void
    {
        if (!Schema::hasTable('barangays')) {
            $this->command?->error('barangays table does not exist.');
            return;
        }

        if (!Schema::hasTable('barangay_heatmaps')) {
            $this->command?->warn('barangay_heatmaps table does not exist. Nothing to sync.');
            return;
        }

        foreach (['latitude', 'longitude'] as $column) {
            if (!Schema::hasColumn('barangays', $column)) {
                $this->command?->error("barangays.{$column} column does not exist.");
                return;
            }

            if (!Schema::hasColumn('barangay_heatmaps', $column)) {
                $this->command?->error("barangay_heatmaps.{$column} column does not exist.");
                return;
            }
        }

        $barangays = DB::table('barangays')
            ->select('barangay_id', 'name', 'latitude', 'longitude')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        $updatedRows = 0;
        $skipped = [];

        DB::transaction(function () use ($barangays, &$updatedRows, &$skipped) {
            foreach ($barangays as $barangay) {
                $lat = round((float) $barangay->latitude, 6);
                $lng = round((float) $barangay->longitude, 6);

                if (!$this->validCoordinate($lat, $lng)) {
                    $skipped[] = (string) $barangay->name;
                    continue;
                }

                $updatedRows += DB::table('barangay_heatmaps')
                    ->where('barangay_id', $barangay->barangay_id)
                    ->update([
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->command?->info("✅ Synced {$updatedRows} barangay_heatmaps coordinate rows from barangays table.");

        if (!empty($skipped)) {
            $this->command?->warn('Skipped invalid barangay coordinates: ' . implode(', ', $skipped));
        }
    }
}
