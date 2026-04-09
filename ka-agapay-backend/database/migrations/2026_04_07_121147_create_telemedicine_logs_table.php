<?php
// database/migrations/2024_02_01_000005_create_telemedicine_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemedicine_logs', function (Blueprint $table) {
            $table->id();
            $table->string('loggable_type')->comment('TelemedicineRequest or TelemedicineSession');
            $table->unsignedBigInteger('loggable_id');
            $table->foreignId('performed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('performed_at')->useCurrent();

            $table->index(['loggable_type', 'loggable_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_logs');
    }
};