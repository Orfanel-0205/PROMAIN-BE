<?php
// database/migrations/xxxx_create_referrals_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();

            // Polymorphic source — what generated this referral
            $table->string('referable_type');
            $table->unsignedBigInteger('referable_id');
            // Consultation, TelemedicineSession, or a direct BHW assessment

            // Who is being referred
            $table->foreignId('resident_profile_id')
                ->constrained('resident_profiles')
                ->restrictOnDelete();

            // Who issued and who is tracking
            $table->foreignId('issued_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();
            $table->foreignId('acknowledged_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->foreignId('assigned_bhw_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete()
                ->comment('BHW assigned to monitor this referral at barangay level');

            // Destination
            $table->string('referral_type', 50);
            // follow_up | specialist | hospital | laboratory | bhw_monitoring | pharmacy
            $table->string('referred_facility', 255)->nullable();
            $table->string('referred_department', 100)
                ->nullable()
                ->comment('e.g., OPD, ER, Laboratory');
            $table->string('referred_physician', 150)->nullable();

            // Clinical content
            $table->text('reason');
            $table->text('clinical_summary')->nullable();
            $table->text('instructions')->nullable();
            $table->string('urgency', 20)->default('routine');
            // routine | urgent | emergency

            // Scheduling
            $table->date('follow_up_date')->nullable();
            $table->time('follow_up_time')->nullable();

            // Lifecycle
            $table->string('status', 30)->default('pending');
            // pending | acknowledged | in_progress | completed | cancelled

            $table->text('outcome_notes')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->boolean('requires_bhw_monitoring')->default(false);
            $table->boolean('is_urgent')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['referable_type', 'referable_id']);
            $table->index(['resident_profile_id', 'status']);
            $table->index(['assigned_bhw_id', 'status']);
            $table->index(['follow_up_date', 'status']);
            $table->index('issued_by');
            $table->index('is_urgent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
