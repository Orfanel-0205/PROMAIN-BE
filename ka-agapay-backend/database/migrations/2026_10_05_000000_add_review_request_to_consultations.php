<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sent to the MHO for review", as on the MHO's Individual Treatment Record.
 *
 * On the paper form a nurse, midwife or BHW fills the vital signs and S, O, A
 * (their assessment) and P (their plan); the doctor writes Remarks &
 * Diagnosis and Prescribe Drug/s and signs. In the system the nurse's part
 * ends with "Send to MHO": the record stays open, the MHO is notified, adds
 * the diagnosis and drugs, and completes it.
 *
 * A timestamp and who sent it, not a new status. The status stays open or
 * ongoing until the MHO completes the record, so every list, report and
 * dashboard that reads the status keeps working as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            if (!Schema::hasColumn('consultations', 'sent_for_review_at')) {
                $table->timestamp('sent_for_review_at')->nullable();
            }

            if (!Schema::hasColumn('consultations', 'sent_for_review_by')) {
                $table->unsignedBigInteger('sent_for_review_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            foreach (['sent_for_review_at', 'sent_for_review_by'] as $column) {
                if (Schema::hasColumn('consultations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
