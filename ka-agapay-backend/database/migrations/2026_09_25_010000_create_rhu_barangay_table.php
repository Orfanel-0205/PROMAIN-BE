<?php
// database/migrations/2026_09_25_010000_create_rhu_barangay_table.php
//
// Lets more than one facility serve the same barangay.
//
// WHY (2026-09-25)
// ---------------
// `barangays.rhu_id` is a single column, so a barangay belonged to exactly one
// RHU. Assigning the municipality to RHU 2 therefore took it away from RHU 1,
// whose barangay-based figures dropped to zero — and the screen said "ticking
// it here moves it away from its current one", which was an accurate
// description of a model that does not match how a municipality works. Malasiqui
// has three RHUs and any of them may see a patient from any barangay, which
// matters most on the day one of them is closed.
//
// TWO DIFFERENT QUESTIONS
// -----------------------
// The old column was answering two at once, and could only answer one:
//
//   "Which RHU does this resident go to by default?"   one facility
//   "Which RHUs are able to serve this barangay?"      several
//
// barangays.rhu_id keeps the first. It still routes a resident's appointments
// and queue tickets, and nothing about that changes. This table answers the
// second, and is what the barangay picker now edits.
//
// Seeded so every active facility covers every barangay, which is the RHU's
// own description of how Malasiqui operates. Narrowing a facility to a
// catchment is then a deliberate act in the admin rather than the default
// nobody chose.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rhus') || !Schema::hasTable('barangays')) {
            return;
        }

        if (!Schema::hasTable('rhu_barangay')) {
            Schema::create('rhu_barangay', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rhu_id');
                $table->unsignedBigInteger('barangay_id');
                $table->timestamps();

                // One row per pair: ticking a barangay twice is not coverage
                // twice over, and the unique index makes that impossible rather
                // than merely unlikely.
                $table->unique(['rhu_id', 'barangay_id']);
                $table->index('rhu_id');
                $table->index('barangay_id');
            });
        }

        if (DB::table('rhu_barangay')->count() > 0) {
            return;
        }

        $rhuIds = DB::table('rhus')->where('is_active', true)->pluck('id');
        $barangayIds = DB::table('barangays')->pluck('barangay_id');

        if ($rhuIds->isEmpty() || $barangayIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($rhuIds as $rhuId) {
            foreach ($barangayIds as $barangayId) {
                $rows[] = [
                    'rhu_id' => (int) $rhuId,
                    'barangay_id' => (int) $barangayId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Three facilities by seventy-three barangays is a couple of hundred
        // rows; chunked anyway so this does not become a single enormous
        // statement if a municipality has far more of either.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('rhu_barangay')->insert($chunk);
        }
    }

    /**
     * Deliberately does not drop the table.
     *
     * Rolling back would discard every coverage decision the RHU has made and
     * silently return the system to one-facility-per-barangay, which is the
     * behaviour this migration exists to end.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
