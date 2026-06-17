<?php
//php artisan migrate:refresh --path=database/migrations/2026_06_12_093652_sync_sms_logs_columns.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sync sms_logs table with the SMS module code.
     */
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_logs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable();
            }

            if (!Schema::hasColumn('sms_logs', 'sent_by')) {
                $table->unsignedBigInteger('sent_by')->nullable();
            }

            if (!Schema::hasColumn('sms_logs', 'recipient_name')) {
                $table->string('recipient_name')->nullable();
            }

            if (!Schema::hasColumn('sms_logs', 'mobile_number')) {
                $table->string('mobile_number', 30);
            }

            if (!Schema::hasColumn('sms_logs', 'message')) {
                $table->text('message');
            }

            if (!Schema::hasColumn('sms_logs', 'mode')) {
                $table->string('mode', 50)->default('single');
            }

            if (!Schema::hasColumn('sms_logs', 'target_filters')) {
                $table->json('target_filters')->nullable();
            }

            if (!Schema::hasColumn('sms_logs', 'notification_type')) {
                $table->string('notification_type', 100)->default('manual');
            }

            if (!Schema::hasColumn('sms_logs', 'provider')) {
                $table->string('provider', 50)->default('semaphore');
            }

            if (!Schema::hasColumn('sms_logs', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable();
            }

            if (!Schema::hasColumn('sms_logs', 'status')) {
                $table->string('status', 50)->default('queued');
            }

            if (!Schema::hasColumn('sms_logs', 'error_message')) {
                $table->text('error_message')->nullable();
            }

            if (!Schema::hasColumn('sms_logs', 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $columns = [
                'sent_by',
                'recipient_name',
                'target_filters',
                'notification_type',
                'provider',
                'provider_message_id',
                'error_message',
                'sent_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('sms_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};