<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->nullable()->after('account_status');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('avatar');
            }
            if (!Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
            // Drop OTP columns if they exist from before
            if (Schema::hasColumn('users', 'otp_code')) {
                $table->dropColumn('otp_code');
            }
            if (Schema::hasColumn('users', 'otp_expires_at')) {
                $table->dropColumn('otp_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'last_login_at', 'last_login_ip']);
        });
    }
};
