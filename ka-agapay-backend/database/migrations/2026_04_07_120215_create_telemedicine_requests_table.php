<?php
// database/migrations/2024_02_01_000001_create_telemedicine_requests_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemedicine_requests', function (Blueprint $table) {
            $table->id();

            // Who is requesting
            $table->foreignId('resident_profile_id')->constrained('resident_profiles')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users', 'user_id')->restrictOnDelete()
                ->comment('The user account who submitted (resident or BHW on behalf)');

            // Optional linkage to queue or appointment
            $table->foreignId('queue_ticket_id')->nullable()->constrained('queue_tickets')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            // Which RHU is this directed to
            $table->foreignId('rhu_id')->constrained('barangays', 'barangay_id')
                ->comment('Target RHU (barangay record serving as RHU)');

            // BHW involvement
            $table->foreignId('endorsed_by_bhw')->nullable()->constrained('users', 'user_id')->nullOnDelete()
                ->comment('BHW who assisted or endorsed this request');
            $table->boolean('is_bhw_assisted')->default(false);
            $table->text('bhw_notes')->nullable();

            // Chief complaint / triage info
            $table->text('chief_complaint');
            $table->enum('urgency_level', ['routine', 'urgent', 'emergency'])->default('routine');
            $table->json('symptoms')->nullable()->comment('Structured symptom list');
            $table->text('additional_notes')->nullable();

            // Screening / triage by staff
            $table->foreignId('screened_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->text('screening_notes')->nullable();
            $table->timestamp('screened_at')->nullable();

            // Request status lifecycle
            $table->enum('status', [
                'pending',       // submitted, awaiting screening
                'screened',      // reviewed by staff, passed triage
                'scheduled',     // session has been created and assigned
                'rejected',      // screened out by staff
                'cancelled',     // withdrawn by resident or admin
                'completed',     // session concluded
            ])->default('pending')->index();

            $table->string('rejection_reason')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['rhu_id', 'status', 'urgency_level']);
            $table->index(['resident_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_requests');
    }
};