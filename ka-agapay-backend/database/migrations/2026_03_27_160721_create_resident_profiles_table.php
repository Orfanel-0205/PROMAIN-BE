<?php
//2026_03_27_160721_create_resident_profiles_table
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('barangay_id')->nullable()->constrained('barangays', 'barangay_id')->nullOnDelete();
            $table->date('birth_date')->nullable();
            $table->string('sex', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('philhealth_no', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_profiles');
    }
};