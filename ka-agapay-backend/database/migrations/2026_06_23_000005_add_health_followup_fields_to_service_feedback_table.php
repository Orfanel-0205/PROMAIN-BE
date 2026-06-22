<?php
// database/migrations/2026_06_23_000005_add_health_followup_fields_to_service_feedback_table.php
//
// Refactors "service feedback" into a medical "Health Follow-up": the patient
// reports their condition after a completed consultation. Purely ADDITIVE +
// idempotent — the table keeps its name (service_feedback) and existing rows /
// columns (rating, comment) are untouched for backward compatibility.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_feedback')) {
            return;
        }

        Schema::table('service_feedback', function (Blueprint $table) {
            if (!Schema::hasColumn('service_feedback', 'followup_type')) {
                $table->string('followup_type', 40)->default('medical_followup');
            }

            // improved | same | worse | recovered
            if (!Schema::hasColumn('service_feedback', 'condition_status')) {
                $table->string('condition_status', 20)->nullable();
            }

            if (!Schema::hasColumn('service_feedback', 'symptoms_present')) {
                $table->boolean('symptoms_present')->nullable();
            }

            // yes | no | not_prescribed | not_applicable
            if (!Schema::hasColumn('service_feedback', 'medication_taken')) {
                $table->string('medication_taken', 20)->nullable();
            }

            if (!Schema::hasColumn('service_feedback', 'side_effects')) {
                $table->boolean('side_effects')->nullable();
            }

            if (!Schema::hasColumn('service_feedback', 'side_effects_description')) {
                $table->text('side_effects_description')->nullable();
            }

            if (!Schema::hasColumn('service_feedback', 'patient_message')) {
                $table->text('patient_message')->nullable();
            }

            if (!Schema::hasColumn('service_feedback', 'needs_follow_up')) {
                $table->boolean('needs_follow_up')->default(false);
            }

            // routine | watch | urgent
            if (!Schema::hasColumn('service_feedback', 'urgency_level')) {
                $table->string('urgency_level', 20)->default('routine');
            }

            if (!Schema::hasColumn('service_feedback', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('service_feedback')) {
            return;
        }

        Schema::table('service_feedback', function (Blueprint $table) {
            foreach ([
                'followup_type',
                'condition_status',
                'symptoms_present',
                'medication_taken',
                'side_effects',
                'side_effects_description',
                'patient_message',
                'needs_follow_up',
                'urgency_level',
                'reviewed_at',
            ] as $column) {
                if (Schema::hasColumn('service_feedback', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
