<?php
// database/migrations/2026_08_30_000000_add_actor_columns_to_inventory_items_table.php
//
// Inventory accountability: who CREATED / last EDITED an item.
//
// The table already carries deleted_by / delete_reason from the soft-delete
// round; these two mirror that exact convention for the other two lifecycle
// ends, so "who touched this item" is answerable from the row itself instead
// of only by scanning audit_logs.
//
// Additive-only and fully guarded (hasTable / hasColumn): safe to re-run
// against live data, drops nothing, and backfills nothing (existing rows keep
// NULL, which honestly means "created before this was tracked" rather than
// inventing an actor who did not do it).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_items')) {
            return;
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_items', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }

            if (!Schema::hasColumn('inventory_items', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory_items')) {
            return;
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            foreach (['created_by', 'updated_by'] as $column) {
                if (Schema::hasColumn('inventory_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
