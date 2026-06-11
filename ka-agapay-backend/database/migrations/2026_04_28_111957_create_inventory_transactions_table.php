<?php
//database/migrations/2026_04_28_111957_create_inventory_transactions_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')
                ->constrained('inventory_items')
                ->cascadeOnDelete();
            $table->foreignId('performed_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->string('transaction_type', 30);
            // stock_in | stock_out | adjustment | expiry_removal | transfer

            $table->integer('quantity_before');
            $table->integer('quantity_changed');
            // positive = added, negative = deducted
            $table->integer('quantity_after');

            // Source — linked to prescription if stock_out
            $table->foreignId('prescription_id')
                ->nullable()
                ->constrained('prescriptions')
                ->nullOnDelete();

            $table->string('reference_number', 50)->nullable();
            // PO number, delivery receipt, etc.

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            // Immutable
            $table->timestamp('created_at')->useCurrent();

            $table->index(['inventory_item_id', 'transaction_type', 'created_at']);
            $table->index(['performed_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
