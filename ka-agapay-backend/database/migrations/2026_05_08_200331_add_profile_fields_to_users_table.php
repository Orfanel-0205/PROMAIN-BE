<?php
//database/migrations/2026_05_08_200331_add_profile_fields_to_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Profile picture URL (stored path in public disk)
            $table->string('profile_picture')->nullable()->after('avatar');

            // Barangay as plain string (validated against fixed list)
            $table->string('barangay', 100)->nullable()->after('profile_picture');

            // Biometric settings
            $table->boolean('biometric_enabled')->default(false)->after('barangay');
            $table->string('biometric_token_hash')->nullable()->after('biometric_enabled');
        });

        // Add birthday to resident_profiles if it doesn't exist yet
        if (Schema::hasTable('resident_profiles')) {
            Schema::table('resident_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('resident_profiles', 'birthday')) {
                    $table->date('birthday')->nullable()->after('sex');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'profile_picture',
                'barangay',
                'biometric_enabled',
                'biometric_token_hash',
            ]);
        });

        if (Schema::hasTable('resident_profiles')) {
            Schema::table('resident_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('resident_profiles', 'birthday')) {
                    $table->dropColumn('birthday');
                }
            });
        }
    }
};