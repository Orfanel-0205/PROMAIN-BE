<?php
// database/migrations/2026_05_27_000001_create_queue_priority_scores_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_priority_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('queue_ticket_id')
                ->constrained('queue_tickets')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('resident_profile_id');
            $table->foreign('resident_profile_id')
                ->references('id')
                ->on('resident_profiles')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('priority_score')->default(0);
            // 0–100 final capped priority score

            $table->string('priority_category', 15);
            // 'Critical' | 'High' | 'Moderate' | 'Low'

            $table->string('queue_type', 20);
            // 'emergency' | 'pregnant' | 'senior' | 'pwd' | 'pre_registered' | 'walk_in'

            $table->jsonb('breakdown')->nullable();
            // Named score components: {"emergency":50,"senior_citizen":20,...}

            $table->jsonb('contributing_factors')->nullable();
            // Human-readable factor list: ["emergency_case","senior_citizen"]

            $table->unsignedTinyInteger('ai_severity_score')->nullable();
            // AI triage severity contribution (0–50), nullable if AI unavailable

            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            // One score record per ticket (latest wins via upsert in service)
            $table->unique('queue_ticket_id', 'qps_ticket_unique');

            // Useful for dashboard queries
            $table->index(['priority_category', 'computed_at'], 'qps_category_date_idx');
            $table->index(['queue_type', 'computed_at'],         'qps_type_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_priority_scores');
    }
};