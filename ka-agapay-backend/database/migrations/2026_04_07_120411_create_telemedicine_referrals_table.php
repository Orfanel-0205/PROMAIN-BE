<?php
// database/migrations/2024_02_01_000004_create_telemedicine_referrals_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemedicine_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('telemedicine_sessions')->cascadeOnDelete();
            $table->foreignId('issued_by')->constrained('users', 'user_id')->restrictOnDelete();
            $table->foreignId('resident_profile_id')->constrained('resident_profiles')->restrictOnDelete();

            $table->enum('referral_type', [
                'follow_up',        // return to same RHU
                'specialist',       // refer to specialist
                'hospital',         // refer to hospital
                'laboratory',       // needs lab work
                'bhw_monitoring',   // BHW to monitor at barangay level
            ]);

            $table->string('referred_to')->nullable()->comment('Name of facility or specialist');
            $table->text('reason');
            $table->text('instructions')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->boolean('is_urgent')->default(false);

            $table->enum('status', ['pending', 'acknowledged', 'completed', 'cancelled'])->default('pending');

            $table->timestamps();

            $table->index(['resident_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_referrals');
    }
};