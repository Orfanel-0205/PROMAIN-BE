<?php
//migrations/2026_04_01_122326_create_queue_tickets_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20)->unique(); // e.g., RHU1-OPD-2024-0001
            $table->foreignId('resident_profile_id')->nullable()->constrained('resident_profiles', 'id')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('rhu_id')->constrained('barangays', 'barangay_id')->comment('Which RHU this ticket belongs to');
            $table->foreignId('issued_by')->nullable()->constrained('users', 'user_id')->nullOnDelete()->comment('Staff or BHW who issued the ticket');
            $table->foreignId('served_by')->nullable()->constrained('users', 'user_id')->nullOnDelete()->comment('Staff who served the patient');

            // Service classification
            $table->enum('service_type', [
                'opd_consultation',
                'prenatal_checkup',
                'immunization',
                'family_planning',
                'tb_dots',
                'laboratory',
                'dental',
                'emergency',
                'medicine_release',
                'bhw_assisted',
            ]);

            // Prioritization
            $table->unsignedTinyInteger('priority_score')->default(0)->comment('Computed score: higher = served first');
            $table->enum('priority_category', [
                'emergency',
                'senior_citizen',
                'pregnant',
                'pwd',
                'pediatric',
                'regular',
            ])->default('regular');

            $table->boolean('is_senior')->default(false);
            $table->boolean('is_pregnant')->default(false);
            $table->boolean('is_pwd')->default(false);
            $table->boolean('is_pediatric')->default(false); // under 5
            $table->boolean('is_emergency')->default(false);
            $table->boolean('is_bhw_endorsed')->default(false);

            // Queue state
            $table->enum('status', [
                'waiting',
                'called',
                'in_service',
                'completed',
                'skipped',
                'cancelled',
                'no_show',
            ])->default('waiting')->index();

            $table->unsignedSmallInteger('queue_position')->nullable()->comment('Position in the queue when generated');
            $table->unsignedSmallInteger('call_attempt')->default(0)->comment('Number of times this ticket was called');

            // Timestamps for SLA tracking
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('service_started_at')->nullable();
            $table->timestamp('service_ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Wait and service time (in minutes) — computed on transition
            $table->unsignedSmallInteger('wait_time_minutes')->nullable();
            $table->unsignedSmallInteger('service_time_minutes')->nullable();

            $table->text('notes')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['rhu_id', 'service_type', 'status', 'priority_score']);
            $table->index(['issued_at']);
            $table->index(['status', 'priority_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
    }
};