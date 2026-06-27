<?php
// database/seeders/BarangayRhuSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use App\Models\Barangay;

/**
 * Barangay -> RHU (facility) mapping seeder.
 *
 * Malasiqui has two Rural Health Units. Every barangay served by RHU 2 is listed
 * in $rhu2Barangays; everything else stays on RHU 1 (the column default).
 *
 * Don Pedro is the confirmed RHU 2 barangay. EXTEND $rhu2Barangays with the rest
 * of the official RHU 2 catchment barangays as the municipality confirms them.
 *
 * Idempotent + non-destructive: it only updates the rhu_id column.
 *   php artisan db:seed --class=BarangayRhuSeeder
 */
class BarangayRhuSeeder extends Seeder
{
    /**
     * Official RHU 2 barangays. Names must match BarangaySeeder exactly.
     *
     * @var string[]
     */
    private array $rhu2Barangays = [
        'Don Pedro',
        // 'Add the rest of the RHU 2 catchment barangays here',
    ];

    public function run(): void
    {
        if (!Schema::hasTable('barangays') || !Schema::hasColumn('barangays', 'rhu_id')) {
            $this->command?->warn('barangays.rhu_id column not found — run migrations first.');
            return;
        }

        // Default everything to RHU 1 first (idempotent baseline).
        Barangay::query()->update(['rhu_id' => 1]);

        // Then promote the RHU 2 catchment barangays.
        $updated = 0;

        foreach ($this->rhu2Barangays as $name) {
            $count = Barangay::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
                ->update(['rhu_id' => 2]);

            if ($count > 0) {
                $updated += $count;
            } else {
                $this->command?->warn("RHU 2 barangay not found in DB: {$name}");
            }
        }

        $rhu1 = Barangay::query()->where('rhu_id', 1)->count();
        $rhu2 = Barangay::query()->where('rhu_id', 2)->count();

        $this->command?->info("Barangay -> RHU mapping set: RHU 1 = {$rhu1}, RHU 2 = {$rhu2} (matched {$updated}).");
    }
}
