<?php
// database/migrations/2024_01_01_000003_create_queue_priority_rules_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_priority_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key')->unique(); // e.g., 'is_emergency', 'is_senior'
            $table->string('label');
            $table->unsignedTinyInteger('score_weight')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_priority_rules');
    }
};