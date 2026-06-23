<?php
// database/migrations/2026_06_24_000003_add_philhealth_verification_to_resident_profiles.php
//
// Additive PhilHealth OCR-verification tracking on resident_profiles.
// The number columns (philhealth_number / philhealth_no) already exist; this
// adds only the verification metadata. Guarded + idempotent; no drops/renames.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('resident_profiles')) {
            return;
        }

        Schema::table('resident_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('resident_profiles', 'philhealth_verified_at')) {
                $table->timestamp('philhealth_verified_at')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'philhealth_ocr_result_id')) {
                $table->unsignedBigInteger('philhealth_ocr_result_id')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'philhealth_name_matched')) {
                $table->boolean('philhealth_name_matched')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('resident_profiles')) {
            return;
        }

        Schema::table('resident_profiles', function (Blueprint $table) {
            foreach ([
                'philhealth_verified_at',
                'philhealth_ocr_result_id',
                'philhealth_name_matched',
            ] as $col) {
                if (Schema::hasColumn('resident_profiles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
