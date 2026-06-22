<?php
// database/migrations/2026_06_22_000000_add_itr_profile_fields_to_resident_profiles_table.php
//
// Adds reusable, NON-CLINICAL patient ITR (Individual Treatment Record) details
// to resident_profiles so they can be filled once by the patient and re-used
// across appointments, queue, and consultations.
//
// Rules followed:
// - These are patient self-reported, reusable details only.
// - No vitals, diagnosis, assessment, plan, prescription, or lab fields here.
// - Safe/idempotent: every column is guarded with Schema::hasColumn, so this can
//   run on databases where some columns already exist (older versions of the
//   resident_profiles table varied between deployments).
// - PostgreSQL compatible.

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
            // Identity / demographic (reusable, non-clinical)
            if (!Schema::hasColumn('resident_profiles', 'civil_status')) {
                $table->string('civil_status', 50)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'religion')) {
                $table->string('religion', 100)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'educational_attainment')) {
                $table->string('educational_attainment', 100)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'occupation')) {
                $table->string('occupation', 150)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'client_type')) {
                $table->string('client_type', 50)->nullable();
            }

            // Contacts / guardian
            if (!Schema::hasColumn('resident_profiles', 'guardian_name')) {
                $table->string('guardian_name', 150)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'emergency_contact_name')) {
                $table->string('emergency_contact_name', 150)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'emergency_contact_number')) {
                $table->string('emergency_contact_number', 30)->nullable();
            }

            // PhilHealth (new canonical column; legacy philhealth_no kept untouched)
            if (!Schema::hasColumn('resident_profiles', 'philhealth_number')) {
                $table->string('philhealth_number', 50)->nullable();
            }

            // Address detail
            if (!Schema::hasColumn('resident_profiles', 'street')) {
                $table->string('street', 150)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'purok')) {
                $table->string('purok', 100)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'household_number')) {
                $table->string('household_number', 50)->nullable();
            }

            // Self-reported history (free text — NOT staff clinical findings)
            if (!Schema::hasColumn('resident_profiles', 'allergies')) {
                $table->text('allergies')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'past_medical_history')) {
                $table->text('past_medical_history')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'maintenance_medications')) {
                $table->text('maintenance_medications')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'family_history')) {
                $table->text('family_history')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'personal_social_history')) {
                $table->text('personal_social_history')->nullable();
            }

            // Lifestyle
            if (!Schema::hasColumn('resident_profiles', 'smoking_status')) {
                $table->string('smoking_status', 30)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'alcohol_intake')) {
                $table->string('alcohol_intake', 30)->nullable();
            }

            // OB / GYN (reusable patient-reported)
            if (!Schema::hasColumn('resident_profiles', 'lmp')) {
                $table->date('lmp')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'menstrual_history')) {
                $table->text('menstrual_history')->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'family_planning_method')) {
                $table->string('family_planning_method', 100)->nullable();
            }
            if (!Schema::hasColumn('resident_profiles', 'pregnancy_history')) {
                $table->text('pregnancy_history')->nullable();
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
                'civil_status',
                'religion',
                'educational_attainment',
                'occupation',
                'client_type',
                'guardian_name',
                'emergency_contact_name',
                'emergency_contact_number',
                'philhealth_number',
                'street',
                'purok',
                'household_number',
                'allergies',
                'past_medical_history',
                'maintenance_medications',
                'family_history',
                'personal_social_history',
                'smoking_status',
                'alcohol_intake',
                'lmp',
                'menstrual_history',
                'family_planning_method',
                'pregnancy_history',
            ] as $column) {
                if (Schema::hasColumn('resident_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
