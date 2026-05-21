<?php
// database/migrations/xxxx_create_prescription_dispensing_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_dispensing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')
                ->constrained('prescriptions')
                ->cascadeOnDelete();
            $table->foreignId('dispensed_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            // What was actually given vs what was prescribed
            $table->jsonb('dispensed_items')
                ->nullable()
                ->comment('Actual items dispensed — may differ from prescription');
            $table->boolean('is_partial_dispense')->default(false);
            $table->text('notes')->nullable();

            // Immutable
            $table->timestamp('dispensed_at')->useCurrent();

            $table->index(['prescription_id', 'dispensed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_dispensing_logs');
    }
};
