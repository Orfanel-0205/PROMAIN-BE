<?php
// database/migrations/2026_06_11_200000_create_sms_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_logs')) {
            return;
        }

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->foreignId('sent_by')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->string('recipient_name')->nullable();
            $table->string('mobile_number', 30);

            $table->text('message');

            $table->string('mode', 50)->default('single');
            $table->json('target_filters')->nullable();

            $table->string('notification_type', 100)->nullable();
            $table->string('provider', 50)->default('semaphore');
            $table->string('provider_message_id')->nullable();

            $table->string('status', 30)->default('queued');
            $table->text('error_message')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['mobile_number']);
            $table->index(['mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};