<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
// In a new migration: php artisan make:migration add_barangay_string_to_users_table
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Only add if not already present
        if (!Schema::hasColumn('users', 'barangay')) {
            $table->string('barangay')->nullable()->after('last_name');
        }
        if (!Schema::hasColumn('users', 'birthday')) {
            $table->date('birthday')->nullable()->after('barangay');
        }
        if (!Schema::hasColumn('users', 'sex')) {
            $table->enum('sex', ['male', 'female', 'other'])->nullable()->after('birthday');
        }
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn(['barangay', 'birthday', 'sex']);
    });
}
};
