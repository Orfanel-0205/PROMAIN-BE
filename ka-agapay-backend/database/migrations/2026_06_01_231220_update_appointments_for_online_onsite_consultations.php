<?php
//database\migrations\2026_06_01_231220_update_appointments_for_online_onsite_consultations.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: change enum-like check/type safely by converting to string
        DB::statement("ALTER TABLE appointments ALTER COLUMN status TYPE VARCHAR(30)");

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

            if (!Schema::hasColumn('appointments', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('symptoms');
            }

            if (!Schema::hasColumn('appointments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            }

            if (!Schema::hasColumn('appointments', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'consultation_type')) {
                $table->dropColumn('consultation_type');
            }

            if (Schema::hasColumn('appointments', 'reason')) {
                $table->dropColumn('reason');
            }

            if (Schema::hasColumn('appointments', 'symptoms')) {
                $table->dropColumn('symptoms');
            }

            if (Schema::hasColumn('appointments', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }

            if (Schema::hasColumn('appointments', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

            if (Schema::hasColumn('appointments', 'scheduled_at')) {
                $table->dropColumn('scheduled_at');
            }
        });
    }
};