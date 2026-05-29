<?php
// database/migrations/YYYY_MM_DD_create_home_visits_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_visits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('patient_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('health_worker_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->date('scheduled_date');
            $table->string('address', 500);
            $table->text('chief_complaint');
            $table->text('notes')->nullable();
            $table->text('visit_notes')->nullable();

            $table->enum('status', [
                'pending',
                'scheduled',
                'completed',
                'cancelled',
            ])->default('pending');

            $table->timestamp('visited_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_visits');
    }
};