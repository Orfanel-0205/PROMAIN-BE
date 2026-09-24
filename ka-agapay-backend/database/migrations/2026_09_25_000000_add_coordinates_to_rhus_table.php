<?php
// database/migrations/2026_09_25_000000_add_coordinates_to_rhus_table.php
//
// Puts facility coordinates in the database, where a new facility can get them.
//
// WHY (2026-09-25)
// ---------------
// The facility map drew its pins from RHU_FACILITIES, a hardcoded array in
// src/services/facilityHeatmap.ts holding RHU 1 and RHU 2. Administration →
// RHU Facilities can open a third, and RHU 3 duly appeared everywhere except
// the one screen that is supposed to show where facilities are: it had no
// coordinates and no way to be given any.
//
// The existing two are seeded from the values that array carried, so the map
// does not move. RHU 2's were already described there as approximate and
// adjustable by the RHU; that is now something the RHU can actually do.
//
// Nullable, because a facility that predates this cannot invent its own
// position. The API requires coordinates when creating a new one, and the map
// skips any facility still missing them rather than dropping a pin in the sea.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Known positions at the time of writing.
     *
     * RHU 1 and RHU 2 come from the frontend constant this replaces. RHU 3 was
     * supplied by the RHU.
     */
    private const KNOWN = [
        'RHU1' => [15.919664, 120.412487],
        'RHU2' => [15.945000, 120.445000],
        'RHU3' => [15.909129, 120.490027],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('rhus')) {
            return;
        }

        Schema::table('rhus', function (Blueprint $table) {
            if (!Schema::hasColumn('rhus', 'latitude')) {
                // Same precision as barangays.latitude, so the two can be
                // compared and drawn on one map without rounding surprises.
                $table->decimal('latitude', 10, 8)->nullable()->after('address');
            }

            if (!Schema::hasColumn('rhus', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }
        });

        foreach (self::KNOWN as $code => [$latitude, $longitude]) {
            DB::table('rhus')
                ->where('code', $code)
                ->whereNull('latitude')
                ->update([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Deliberately does not drop the columns.
     *
     * Rolling back would discard the position of every facility added since,
     * and the map simply ignores a facility without coordinates -- so an unused
     * column costs nothing while losing one costs a support call.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
