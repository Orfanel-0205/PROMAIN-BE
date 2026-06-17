<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_transactions')) {
            return;
        }

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('inventory_item_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_id')->nullable()->index();
            $table->unsignedBigInteger('item_id')->nullable()->index();
            $table->unsignedBigInteger('medicine_id')->nullable()->index();

            $table->string('transaction_type')->nullable();
            $table->string('type')->nullable();

            $table->integer('quantity')->default(0);
            $table->integer('quantity_before')->nullable();
            $table->integer('quantity_after')->nullable();
            $table->integer('stock_before')->nullable();
            $table->integer('stock_after')->nullable();

            $table->string('unit')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('expiration_date')->nullable();

            $table->text('reason')->nullable();
            $table->text('remarks')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('performed_by')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};