<?php
//database/migrations/2026_04_28_111913_create_inventory_items_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rhu_id')
                ->comment('Which RHU owns this stock');
            $table->string('item_code', 30)->unique();
            // Format: MED-2026-0001

            $table->string('name', 200);
            $table->string('generic_name', 200)->nullable();
            $table->string('category', 50);
            // medicine | vaccine | supply | equipment

            $table->string('unit_of_measure', 30);
            // tablet | capsule | vial | bottle | piece

            $table->string('dosage_form', 50)->nullable();
            // tablet | capsule | syrup | injection | drops

            // Stock levels
            $table->integer('current_stock')->default(0);
            $table->integer('minimum_stock_level')->default(10)
                ->comment('Alert triggered below this');
            $table->integer('maximum_stock_level')->nullable();
            $table->integer('reorder_point')->default(20);

            // Dates
            $table->date('expiration_date')->nullable();
            $table->date('last_restocked_at')->nullable();

            // Classification
            $table->boolean('is_controlled_substance')->default(false);
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['rhu_id', 'category', 'is_active']);
            $table->index(['current_stock', 'minimum_stock_level']);
            $table->index('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
