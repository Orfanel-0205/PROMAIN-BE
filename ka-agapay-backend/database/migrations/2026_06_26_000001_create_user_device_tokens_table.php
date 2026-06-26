<?php
// database/migrations/2026_06_26_000001_create_user_device_tokens_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::create('user_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('token', 255)->unique();
            $table->string('provider', 30)->default('expo');
            $table->string('platform', 30)->nullable();
            $table->string('device_name', 150)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('channel_id', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider', 'is_active']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};
