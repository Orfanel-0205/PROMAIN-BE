<?php
// database/migrations/xxxx_create_prescriptions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();

            // Clinical source — at least one must be set
            $table->foreignId('resident_profile_id')
                ->constrained('resident_profiles')
                ->restrictOnDelete();
            $table->foreignId('prescribed_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->foreignId('consultation_id')
                ->nullable()
                ->constrained('consultations')
                ->nullOnDelete();
            $table->foreignId('telemedicine_session_id')
                ->nullable()
                ->constrained('telemedicine_sessions')
                ->nullOnDelete();

            // Prescription identity
            $table->string('prescription_number', 30)->unique();
            // Format: RHU1-RX-2024-0001
            $table->unsignedTinyInteger('rhu_id')
                ->comment('Which RHU issued this prescription');
            $table->date('prescription_date');
            $table->date('valid_until')
                ->nullable()
                ->comment('PH law: most Rx valid 7 days from issuance');

            // Clinical content
            $table->text('diagnosis')->nullable();
            $table->string('diagnosis_code', 20)
                ->nullable()
                ->comment('ICD-10 code');

            // Medications stored as structured JSONB
            $table->jsonb('medications');
            /*
                [
                    {
                        "name": "Amoxicillin",
                        "generic_name": "Amoxicillin Trihydrate",
                        "dosage": "500mg",
                        "dosage_form": "capsule",
                        "quantity": 21,
                        "frequency": "TID",
                        "duration": "7 days",
                        "route": "oral",
                        "instructions": "Take after meals",
                        "is_controlled": false,
                        "brand_alternatives_allowed": true
                    }
                ]
            */

            // Controlled substance tracking (DOH compliance)
            $table->boolean('has_controlled_substances')->default(false);
            $table->string('s2_license_number', 50)
                ->nullable()
                ->comment("Prescriber S2 license — required if controlled");

            // Instructions
            $table->text('additional_instructions')->nullable();
            $table->text('dispensing_notes')
                ->nullable()
                ->comment('For the pharmacist');

            // Status lifecycle
            $table->string('status', 30)->default('active');
            // active | dispensed | partially_dispensed | expired | cancelled | voided

            // Dispensing tracking
            $table->timestamp('dispensed_at')->nullable();
            $table->foreignId('dispensed_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            // Voiding
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->string('void_reason', 500)->nullable();

            // Generated PDF path
            $table->string('file_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Performance indexes
            $table->index(['resident_profile_id', 'status']);
            $table->index(['prescribed_by', 'prescription_date']);
            $table->index(['prescription_date', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
