<?php
// database/migrations/2026_09_01_000000_create_inventory_personnel_table.php
//
// Part 2(a): the staff member formally responsible for inventory at an RHU.
//
// ONE designated person per RHU (unique on rhu_id) — the accountability flag
// reads "not the assigned inventory personnel (X)", which only makes sense with
// a single name. Re-designating overwrites the row rather than adding a second.
//
// This is a DISPLAY/accountability designation, not an authorization gate:
// nobody loses the ability to move stock because of it. Who actually performed
// each movement continues to come from inventory_transactions.performed_by.
//
// Additive-only and guarded — creates a new table, alters nothing existing.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_personnel')) {
            return;
        }

        Schema::create('inventory_personnel', function (Blueprint $table) {
            $table->id();

            // One assignment per RHU.
            $table->unsignedBigInteger('rhu_id')->unique();

            // The designated staff member. Nullable so an assignment can be
            // cleared without deleting the audit of who cleared it.
            $table->unsignedBigInteger('user_id')->nullable();

            // Who made the designation, and when.
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_personnel');
    }
};
