<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_transactions')) {
            return;
        }

        if (!Schema::hasColumn('inventory_transactions', 'quantity_before')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->integer('quantity_before')->default(0);
            });
        }

        if (!Schema::hasColumn('inventory_transactions', 'quantity_changed')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->integer('quantity_changed')->default(0);
            });
        }

        if (!Schema::hasColumn('inventory_transactions', 'quantity_after')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->integer('quantity_after')->default(0);
            });
        }

        if (!Schema::hasColumn('inventory_transactions', 'reason')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->string('reason', 500)->nullable();
            });
        }

        if (!Schema::hasColumn('inventory_transactions', 'notes')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->text('notes')->nullable();
            });
        }

        if (!Schema::hasColumn('inventory_transactions', 'reference_number')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->string('reference_number', 100)->nullable();
            });
        }

        if (!Schema::hasColumn('inventory_transactions', 'prescription_id')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('prescription_id')->nullable();
            });
        }

        if (!Schema::hasColumn('inventory_transactions', 'updated_at')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable();
            });
        }

        DB::table('inventory_transactions')
            ->whereNull('quantity_changed')
            ->update(['quantity_changed' => 0]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_transactions ALTER COLUMN quantity_changed SET DEFAULT 0');
        }
    }

    public function down(): void
    {
        // No destructive rollback. These columns are needed for inventory audit history.
    }
};