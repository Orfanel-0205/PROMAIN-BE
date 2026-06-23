<?php
// database/migrations/2026_06_24_000001_add_clinical_and_itr_fields.php
//
// Additive, idempotent migration that completes the ITR (Individual Treatment
// Record) data model:
//
//  - consultations: RHU/doctor-filled clinical fields (vitals, pediatric client,
//    general survey, prescribed drugs). These are staff findings, kept on the
//    consultation so completed records carry the full SOAP + clinical picture.
//
//  - resident_profiles: the few patient-reported ITR fields that were still
//    missing (guardian birthdate, blood type, and female-specific fields).
//
// Rules:
//  - Every column is guarded with Schema::hasColumn (safe to re-run).
//  - No column is dropped or renamed.
//  - Vitals are stored as short strings (e.g. BP "120/80", WT "65 kg") so the
//    UI never has to coerce/parse free-form clinical text.
//  - PostgreSQL + MySQL compatible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consultations')) {
            Schema::table('consultations', function (Blueprint $table) {
                // Vitals / RHU staff filled
                $this->addString($table, 'consultations', 'vital_signs', 255);
                $this->addString($table, 'consultations', 'weight', 50);
                $this->addString($table, 'consultations', 'bmi', 50);
                $this->addString($table, 'consultations', 'temperature_celsius', 50);
                $this->addString($table, 'consultations', 'blood_pressure', 50);
                $this->addString($table, 'consultations', 'spo2', 50);
                $this->addString($table, 'consultations', 'heart_rate', 50);
                $this->addString($table, 'consultations', 'visual_acuity', 100);
                $this->addString($table, 'consultations', 'visual_acuity_left', 50);
                $this->addString($table, 'consultations', 'visual_acuity_right', 50);

                // Pediatric client measurements
                $this->addBoolean($table, 'consultations', 'pediatric_client');
                $this->addString($table, 'consultations', 'length_cm', 50);
                $this->addString($table, 'consultations', 'head_circumference_cm', 50);
                $this->addString($table, 'consultations', 'skinfold_thickness_cm', 50);
                $this->addString($table, 'consultations', 'waist_cm', 50);
                $this->addString($table, 'consultations', 'hip_cm', 50);
                $this->addString($table, 'consultations', 'limbs_cm', 50);
                $this->addString($table, 'consultations', 'muac_cm', 50);

                // General survey
                $this->addString($table, 'consultations', 'general_survey', 100);
                $this->addBoolean($table, 'consultations', 'awake_and_alert');
                $this->addBoolean($table, 'consultations', 'altered_sensorium');

                // Prescription summary (free text; the e-prescription module is separate)
                $this->addText($table, 'consultations', 'prescribed_drugs');
            });
        }

        if (Schema::hasTable('resident_profiles')) {
            Schema::table('resident_profiles', function (Blueprint $table) {
                $this->addDate($table, 'resident_profiles', 'guardian_birthdate');
                $this->addString($table, 'resident_profiles', 'blood_type', 10);

                // Female-specific ITR fields
                $this->addString($table, 'resident_profiles', 'number_of_children', 20);
                $this->addString($table, 'resident_profiles', 'period_duration', 50);
                $this->addString($table, 'resident_profiles', 'cycle', 50);
                $this->addString($table, 'resident_profiles', 'menopausal_age', 20);
            });
        }
    }

    public function down(): void
    {
        $consultationCols = [
            'vital_signs', 'weight', 'bmi', 'temperature_celsius', 'blood_pressure',
            'spo2', 'heart_rate', 'visual_acuity', 'visual_acuity_left', 'visual_acuity_right',
            'pediatric_client', 'length_cm', 'head_circumference_cm', 'skinfold_thickness_cm',
            'waist_cm', 'hip_cm', 'limbs_cm', 'muac_cm',
            'general_survey', 'awake_and_alert', 'altered_sensorium', 'prescribed_drugs',
        ];

        $profileCols = [
            'guardian_birthdate', 'blood_type', 'number_of_children',
            'period_duration', 'cycle', 'menopausal_age',
        ];

        if (Schema::hasTable('consultations')) {
            Schema::table('consultations', function (Blueprint $table) use ($consultationCols) {
                foreach ($consultationCols as $col) {
                    if (Schema::hasColumn('consultations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('resident_profiles')) {
            Schema::table('resident_profiles', function (Blueprint $table) use ($profileCols) {
                foreach ($profileCols as $col) {
                    if (Schema::hasColumn('resident_profiles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    private function addString(Blueprint $table, string $tbl, string $col, int $len): void
    {
        if (!Schema::hasColumn($tbl, $col)) {
            $table->string($col, $len)->nullable();
        }
    }

    private function addText(Blueprint $table, string $tbl, string $col): void
    {
        if (!Schema::hasColumn($tbl, $col)) {
            $table->text($col)->nullable();
        }
    }

    private function addBoolean(Blueprint $table, string $tbl, string $col): void
    {
        if (!Schema::hasColumn($tbl, $col)) {
            $table->boolean($col)->nullable();
        }
    }

    private function addDate(Blueprint $table, string $tbl, string $col): void
    {
        if (!Schema::hasColumn($tbl, $col)) {
            $table->date($col)->nullable();
        }
    }
};
