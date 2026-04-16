<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('mobile_number', 20);
            $table->text('message');
            $table->string('notification_type', 100)->nullable();
            $table->string('provider', 50)->default('semaphore'); // semaphore, globe_labs, etc.
            $table->string('provider_message_id')->nullable();
            $table->string('status', 30)->default('pending'); // pending, sent, failed, delivered
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'status']);
            $table->index(['mobile_number', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
