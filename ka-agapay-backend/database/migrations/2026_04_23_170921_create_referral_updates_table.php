<?php
// database/migrations/xxxx_create_referral_updates_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')
                ->constrained('referrals')
                ->cascadeOnDelete();
            $table->foreignId('updated_by')
                ->constrained('users', 'user_id')
                ->restrictOnDelete();

            $table->string('update_type', 50);
            // created | status_change | bhw_report | rescheduled | outcome_recorded

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();

            // Immutable
            $table->timestamp('created_at')->useCurrent();

            $table->index(['referral_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_updates');
    }
};
