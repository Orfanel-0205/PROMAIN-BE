<?php
// database/migrations/2024_02_01_000002_create_telemedicine_sessions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemedicine_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_id')->unique()->constrained('telemedicine_requests')->cascadeOnDelete()
                ->comment('One session per approved request');

            // Assigned doctor
            $table->foreignId('assigned_doctor_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();

            // BHW co-presence (optional)
            $table->foreignId('bhw_companion_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();

            // Scheduling
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->unsignedSmallInteger('estimated_duration_minutes')->default(15);

            // Connection details
            $table->enum('session_mode', ['video_call', 'voice_call', 'chat', 'in_app'])->default('in_app');
            $table->string('session_link', 500)->nullable()->comment('Video call link or room ID if external');
            $table->string('session_token', 100)->nullable()->comment('Internal session token for in-app mode');

            // Session state
            $table->enum('status', [
                'scheduled',    // created and waiting for session start time
                'waiting',      // doctor/patient logged in, session room open
                'active',       // consultation in progress
                'paused',       // temporarily on hold
                'ended',        // doctor ended the session
                'no_show',      // patient did not join
                'cancelled',    // cancelled before session started
            ])->default('scheduled')->index();

            // Actual runtime
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedSmallInteger('actual_duration_minutes')->nullable();

            // Linked consultation record (created when session ends)
            $table->foreignId('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();

            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['assigned_doctor_id', 'scheduled_date', 'status']);
            $table->index(['scheduled_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_sessions');
    }
};