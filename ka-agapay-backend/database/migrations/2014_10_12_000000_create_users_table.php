<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->foreignId('role_id')->constrained('user_roles', 'role_id');
            $table->foreignId('barangay_id')->nullable()->constrained('barangays', 'barangay_id')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->unique()->nullable();
            $table->string('mobile_number', 20)->unique();
            $table->string('password');
            $table->enum('account_status', ['pending', 'active', 'inactive', 'rejected'])->default('pending');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};