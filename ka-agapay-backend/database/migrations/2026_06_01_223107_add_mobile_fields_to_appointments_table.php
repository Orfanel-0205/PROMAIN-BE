<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (!Schema::hasColumn('appointments', 'consultation_type')) {
                $table->string('consultation_type', 20)->default('onsite')->after('notes');
            }

            if (!Schema::hasColumn('appointments', 'reason')) {
                $table->text('reason')->nullable()->after('consultation_type');
            }

            if (!Schema::hasColumn('appointments', 'symptoms')) {
                $table->text('symptoms')->nullable()->after('reason');
            }
        });

        // PostgreSQL-safe normalization for old rows
        DB::table('appointments')
            ->whereNull('consultation_type')
            ->update(['consultation_type' => 'onsite']);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'symptoms')) {
                $table->dropColumn('symptoms');
            }

            if (Schema::hasColumn('appointments', 'reason')) {
                $table->dropColumn('reason');
            }

            if (Schema::hasColumn('appointments', 'consultation_type')) {
                $table->dropColumn('consultation_type');
            }
        });
    }
};