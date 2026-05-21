<?php
// database/migrations/2024_02_01_000003_create_telemedicine_session_notes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemedicine_session_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('telemedicine_sessions')->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users', 'user_id')->restrictOnDelete();

            // SOAP-structure
            $table->text('subjective')->nullable()->comment('Chief complaint, history as told by patient');
            $table->text('objective')->nullable()->comment('Observed vitals, signs reported or measured');
            $table->text('assessment')->nullable()->comment('Diagnosis or differential diagnosis');
            $table->text('plan')->nullable()->comment('Treatment, prescriptions, referrals, follow-up');

            // Structured field
            $table->string('primary_diagnosis_code', 20)->nullable()->comment('ICD-10 code');
            $table->string('primary_diagnosis_label')->nullable();
            $table->json('medications')->nullable()->comment('Array of prescribed medications');
            $table->boolean('is_finalized')->default(false);
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_session_notes');
    }
};