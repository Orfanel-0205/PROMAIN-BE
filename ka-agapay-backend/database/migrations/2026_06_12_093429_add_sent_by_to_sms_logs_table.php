<?php
// php artisan migrate --path=database/migrations/2026_06_12_093429_add_sent_by_to_sms_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the admin/staff user who sent the SMS.
     */
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_logs', 'sent_by')) {
                $table->unsignedBigInteger('sent_by')->nullable()->after('user_id');
                $table->index('sent_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (Schema::hasColumn('sms_logs', 'sent_by')) {
                $table->dropIndex(['sent_by']);
                $table->dropColumn('sent_by');
            }
        });
    }
};