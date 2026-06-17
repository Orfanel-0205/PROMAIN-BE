<?php
// php artisan migrate --path=database/migrations/2026_06_12_094103_add_timestamps_to_sms_logs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Laravel timestamp columns required by Eloquent.
     */
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('sms_logs', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (!Schema::hasColumn('sms_logs', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (Schema::hasColumn('sms_logs', 'created_at')) {
                $table->dropColumn('created_at');
            }

            if (Schema::hasColumn('sms_logs', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};