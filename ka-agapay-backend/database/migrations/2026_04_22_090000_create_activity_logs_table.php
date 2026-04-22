<?php
// database/migrations/2026_04_22_090000_create_activity_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();
            $table->string('user_role', 50)->nullable()
                ->comment('Snapshot of role at time of action — survives role changes');

            $table->string('action', 100);
            // Format: module.verb e.g. 'queue_ticket.called', 'prescription.issued'
            $table->string('module', 50);
            $table->string('severity', 20)->default('info');
            // info | warning | critical

            // Subject — what was acted on
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable();

            // Change tracking
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();

            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('route_name', 150)->nullable();

            // Immutable — no updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['severity', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
