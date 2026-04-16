<?php
// database/migrations/2024_01_01_000004_create_queue_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_ticket_id')->constrained('queue_tickets')->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('action'); // 'issued', 'called', 'in_service', 'completed', 'skipped', 'cancelled', 'no_show'
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->json('metadata')->nullable(); // any extra context (IP, device, call_attempt, etc.)
            $table->timestamp('performed_at')->useCurrent();

            $table->index(['queue_ticket_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_logs');
    }
};