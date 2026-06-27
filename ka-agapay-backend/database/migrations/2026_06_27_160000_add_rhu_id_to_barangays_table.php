<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barangay -> RHU mapping.
 *
 * Malasiqui has two Rural Health Units (RHU 1 and RHU 2). Each barangay is
 * served by exactly one of them. This column records that assignment so the
 * resident's RHU can be derived from their barangay during registration and so
 * appointments/queue/etc. route to the correct facility.
 *
 * Default 1 keeps every existing barangay on RHU 1 until the official RHU 2
 * barangay list is seeded (see config('kaagapay.rhu2_barangays') +
 * RhuBarangayMapSeeder). Guarded + idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('barangays') || Schema::hasColumn('barangays', 'rhu_id')) {
            return;
        }

        Schema::table('barangays', function (Blueprint $table) {
            $table->unsignedTinyInteger('rhu_id')->default(1)->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('barangays') || !Schema::hasColumn('barangays', 'rhu_id')) {
            return;
        }

        Schema::table('barangays', function (Blueprint $table) {
            try {
                $table->dropIndex(['rhu_id']);
            } catch (\Throwable) {
                // no-op
            }

            $table->dropColumn('rhu_id');
        });
    }
};
