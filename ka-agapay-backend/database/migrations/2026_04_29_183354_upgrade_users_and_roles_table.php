<?php
//database/migrations/2026_04_29_183354_upgrade_users_and_roles_table.php
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
        Schema::table('user_roles', function (Blueprint $table) {
            if (!Schema::hasColumn('user_roles', 'permissions')) {
                $table->jsonb('permissions')->default('{}')->after('name');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'otp_code')) {
                $table->string('otp_code', 10)->nullable()->after('account_status');
            }
            if (!Schema::hasColumn('users', 'otp_expires_at')) {
                $table->timestamp('otp_expires_at')->nullable()->after('otp_code');
            }
            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('otp_expires_at');
            }
            if (!Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
            if (!Schema::hasColumn('users', 'failed_login_count')) {
                $table->integer('failed_login_count')->default(0)->after('last_login_ip');
            }
            if (!Schema::hasColumn('users', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->after('failed_login_count');
            }
            if (!Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_verified_at',
                'otp_code',
                'otp_expires_at',
                'last_login_at',
                'last_login_ip',
                'failed_login_count',
                'locked_until',
            ]);
            $table->dropSoftDeletes();
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }

};
