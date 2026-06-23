<?php
// app/Http/Controllers/Api/ReportController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\DiagnosisItrReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly DiagnosisItrReportService $diagnosisItr)
    {
    }

    /**
     * GET /api/v1/reports/consultations/diagnosis-itr
     *
     * Visible Diagnosis + ITR rows for the web Reports table.
     */
    public function diagnosisItr(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rows = $this->diagnosisItr->rows($filters, $request->user());

        return response()->json([
            'status' => 'success',
            'generated_at' => now()->toIso8601String(),
            'filters' => $filters,
            'summary' => [
                'total_completed_consultations' => $rows->count(),
                'total_diagnosed_consultations' => $rows
                    ->filter(fn ($row) => trim((string) ($row['diagnosis'] ?? '')) !== '')
                    ->count(),
                'followups_scheduled' => $rows
                    ->filter(fn ($row) => (bool) ($row['follow_up_needed'] ?? false))
                    ->count(),
            ],
            'data' => $rows->values(),
        ]);
    }

    /**
     * GET /api/v1/reports/consultations/export
     *
     * ITR + SOAP + Diagnosis CSV Export.
     *
     * CSV design:
     * - ITR / Patient-filled section:
     *   patient details, PhilHealth, address, guardian, history, reason/chief complaint.
     *
     * - SOAP / Doctor-filled section:
     *   Objective, Assessment, Plan, Remarks & Diagnosis, Prescribed Drug/s.
     */
    public function exportDiagnosisItrCsv(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->diagnosisItr->rows($filters, $request->user());

        $columns = [
            // ================================================================
            // SESSION / VISIT DETAILS
            // ================================================================
            'Session: Consultation ID' => fn (array $row) => $this->value($row, ['consultation_id']),
            'Session: Consultation Date' => fn (array $row) => $this->value($row, ['consultation_date']),
            'Session: First Attended At' => fn (array $row) => $this->value($row, ['first_attended_at']),
            'Session: Completed At' => fn (array $row) => $this->value($row, ['completed_at']),
            'Session: Status' => fn (array $row) => $this->value($row, ['status']),
            'Session: RHU' => fn (array $row) => $this->rhuLabel($this->value($row, ['rhu_id'])),
            'Session: Queue Number' => fn (array $row) => $this->value($row, ['queue_number']),
            'Session: Queue Source' => fn (array $row) => $this->value($row, ['queue_source']),
            'Session: Appointment Type' => fn (array $row) => $this->value($row, ['appointment_type']),

            // ================================================================
            // ITR / PATIENT-FILLED DETAILS
            // These are the details that should come from resident profile,
            // registration, or appointment booking before the doctor fills SOAP.
            // ================================================================
            'ITR: PhilHealth Number / ID' => fn (array $row) => $this->value($row, ['philhealth_id']),
            'ITR: PhilHealth Verified' => fn (array $row) => $this->value($row, ['philhealth_verified']),
            'ITR: Patient Full Name' => fn (array $row) => $this->value($row, ['patient_name']),
            'ITR: First Name' => fn (array $row) => $this->value($row, ['first_name', 'profile_first_name']),
            'ITR: Middle Name' => fn (array $row) => $this->value($row, ['middle_name', 'profile_middle_name']),
            'ITR: Last Name' => fn (array $row) => $this->value($row, ['last_name', 'profile_last_name']),
            'ITR: Suffix' => fn (array $row) => $this->value($row, ['suffix', 'profile_suffix']),
            'ITR: Client Type' => fn (array $row) => $this->value($row, ['client_type', 'appointment_type']),
            'ITR: Address' => fn (array $row) => $this->value($row, ['address']),
            'ITR: Barangay' => fn (array $row) => $this->value($row, ['barangay']),
            'ITR: Mobile Number' => fn (array $row) => $this->value($row, ['mobile_number']),
            'ITR: Age' => fn (array $row) => $this->value($row, ['age']),
            'ITR: DOH Age Group' => fn (array $row) => $this->dohAgeGroup($this->value($row, ['age'])),
            'ITR: Birthdate' => fn (array $row) => $this->value($row, ['birthdate']),
            'ITR: Gender / Sex' => fn (array $row) => $this->value($row, ['sex_gender']),
            'ITR: Civil Status' => fn (array $row) => $this->value($row, ['civil_status']),
            'ITR: Religion' => fn (array $row) => $this->value($row, ['religion']),
            'ITR: Education' => fn (array $row) => $this->value($row, ['educational_attainment', 'education']),
            'ITR: Guardian Name' => fn (array $row) => $this->value($row, ['guardian_name']),
            'ITR: Guardian Birthdate' => fn (array $row) => $this->value($row, ['guardian_birthdate']),
            'ITR: Guardian Contact' => fn (array $row) => $this->value($row, ['guardian_contact']),

            // ================================================================
            // VITALS / BODY MEASURES
            // These will export blank if not yet saved in the database.
            // ================================================================
            'Vitals: V/S' => fn (array $row) => $this->value($row, ['vital_signs']),
            'Vitals: Blood Pressure' => fn (array $row) => $this->value($row, ['blood_pressure', 'bp']),
            'Vitals: Temperature (Celsius)' => fn (array $row) => $this->value($row, ['temperature_celsius', 'temperature']),
            'Vitals: Heart Rate' => fn (array $row) => $this->value($row, ['heart_rate', 'hr']),
            'Vitals: SpO2' => fn (array $row) => $this->value($row, ['spo2']),
            'Vitals: Respiratory Rate' => fn (array $row) => $this->value($row, ['respiratory_rate', 'rr']),
            'Vitals: Weight' => fn (array $row) => $this->value($row, ['weight', 'weight_kg']),
            'Vitals: Height' => fn (array $row) => $this->value($row, ['height', 'height_cm']),
            'Vitals: BMI' => fn (array $row) => $this->value($row, ['bmi']),
            'ITR: Blood Type' => fn (array $row) => $this->value($row, ['blood_type']),
            'Vitals: Visual Acuity' => fn (array $row) => $this->value($row, ['visual_acuity']),
            'Vitals: Visual Acuity Left' => fn (array $row) => $this->value($row, ['visual_acuity_left']),
            'Vitals: Visual Acuity Right' => fn (array $row) => $this->value($row, ['visual_acuity_right']),

            // ================================================================
            // FEMALE / PEDIATRIC / HISTORY SECTION
            // These match the paper ITR layout. If the app is not collecting
            // these yet, the CSV column remains blank until the profile form is updated.
            // ================================================================
            'ITR: Pediatric Client 0-24 Months' => fn (array $row) => $this->pediatricLabel($this->value($row, ['age'])),
            'ITR: Length' => fn (array $row) => $this->value($row, ['length_cm']),
            'ITR: Head Circumference' => fn (array $row) => $this->value($row, ['head_circumference_cm']),
            'ITR: Skinfold Thickness' => fn (array $row) => $this->value($row, ['skinfold_thickness_cm']),
            'ITR: Waist' => fn (array $row) => $this->value($row, ['waist_cm']),
            'ITR: Hip' => fn (array $row) => $this->value($row, ['hip_cm']),
            'ITR: Limbs' => fn (array $row) => $this->value($row, ['limbs_cm']),
            'ITR: MUAC' => fn (array $row) => $this->value($row, ['muac_cm']),
            'ITR: Pediatric Client (flag)' => fn (array $row) => $this->value($row, ['pediatric_client']),

            // General survey (RHU staff filled)
            'Survey: General Survey' => fn (array $row) => $this->value($row, ['general_survey']),
            'Survey: Awake and Alert' => fn (array $row) => $this->value($row, ['awake_and_alert']),
            'Survey: Altered Sensorium' => fn (array $row) => $this->value($row, ['altered_sensorium']),

            'ITR: Female - No. of Child' => fn (array $row) => $this->value($row, ['number_of_children']),
            'ITR: Female - LMP' => fn (array $row) => $this->value($row, ['lmp']),
            'ITR: Female - Period Duration' => fn (array $row) => $this->value($row, ['period_duration']),
            'ITR: Female - Cycle' => fn (array $row) => $this->value($row, ['cycle']),
            'ITR: Female - FP Method' => fn (array $row) => $this->value($row, ['family_planning_method', 'fp_method']),
            'ITR: Female - Menopausal Age' => fn (array $row) => $this->value($row, ['menopausal_age']),

            'ITR: Personal/Social - Smoking' => fn (array $row) => $this->value($row, ['smoking', 'is_smoking']),
            'ITR: Personal/Social - Alcohol Intake' => fn (array $row) => $this->value($row, ['alcohol_intake', 'is_alcohol_intake']),
            'ITR: Allergies' => fn (array $row) => $this->value($row, ['allergies']),
            'ITR: Past Medical History' => fn (array $row) => $this->value($row, ['past_medical_history', 'medical_history']),
            'ITR: Maintenance Medications' => fn (array $row) => $this->value($row, ['maintenance_medications']),
            'ITR: Family History' => fn (array $row) => $this->value($row, ['family_history']),
            'ITR: Personal/Social History' => fn (array $row) => $this->value($row, ['personal_social_history']),

            // ================================================================
            // PATIENT REASON / SUBJECTIVE
            // Subjective should represent the patient complaint/reason.
            // ================================================================
            'ITR/SOAP: Appointment Reason' => fn (array $row) => $this->value($row, ['appointment_reason']),
            'ITR/SOAP: Chief Complaint' => fn (array $row) => $this->value($row, ['chief_complaint', 'appointment_reason']),
            'SOAP: Subjective - Patient Complaint/Reason' => fn (array $row) => $this->value($row, [
                'subjective',
                'chief_complaint',
                'appointment_reason',
            ]),

            // ================================================================
            // DOCTOR / RHU STAFF SOAP SECTION
            // OAP, Remarks & Diagnosis, and Prescribed Drugs should be filled by doctor.
            // ================================================================
            'SOAP: Objective - Doctor Filled' => fn (array $row) => $this->value($row, ['objective']),
            'SOAP: Assessment - Doctor Filled' => fn (array $row) => $this->value($row, ['assessment', 'diagnosis']),
            'SOAP: Plan - Doctor Filled' => fn (array $row) => $this->value($row, ['plan', 'treatment']),
            'SOAP: Remarks' => fn (array $row) => $this->value($row, ['notes']),
            'SOAP: Remarks & Diagnosis' => fn (array $row) => $this->combine([
                $this->value($row, ['notes']),
                $this->value($row, ['diagnosis', 'assessment']),
            ]),
            'SOAP: Diagnosis' => fn (array $row) => $this->value($row, ['diagnosis', 'assessment']),
            'SOAP: Prescribed Drug/s / Treatment' => fn (array $row) => $this->value($row, [
                'prescribed_drugs',
                'prescription',
                'treatment',
                'plan',
            ]),

            // ================================================================
            // FOLLOW-UP
            // ================================================================
            'Follow-up Needed' => fn (array $row) => $this->yesNo($this->value($row, ['follow_up_needed'])),
            'Follow-up Date / Time' => fn (array $row) => $this->value($row, ['follow_up_date_time']),
            'Follow-up Instructions' => fn (array $row) => $this->value($row, ['follow_up_instructions']),
            'Follow-up Status' => fn (array $row) => $this->value($row, ['follow_up_status']),

            // ================================================================
            // STAFF
            // ================================================================
            'Doctor / Attending Staff' => fn (array $row) => $this->value($row, ['attending_staff']),
        ];

        $filename = 'itr_soap_diagnosis_consultation_report_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM for Microsoft Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_keys($columns));

            foreach ($rows as $row) {
                $row = is_array($row) ? $row : (array) $row;

                $csvRow = [];

                foreach ($columns as $resolver) {
                    $csvRow[] = $this->csvCell($resolver($row));
                }

                fputcsv($out, $csvRow);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],

            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],

            'rhu_id' => ['nullable'],
            'barangay_id' => ['nullable'],

            'diagnosis' => ['nullable', 'string', 'max:150'],
            'disease' => ['nullable', 'string', 'max:150'],
        ]);

        return [
            'date_from' => $validated['date_from'] ?? $validated['from'] ?? null,
            'date_to' => $validated['date_to'] ?? $validated['to'] ?? null,
            'rhu_id' => $validated['rhu_id'] ?? null,
            'barangay_id' => $validated['barangay_id'] ?? null,
            'diagnosis' => trim((string) ($validated['diagnosis'] ?? $validated['disease'] ?? '')) ?: null,
        ];
    }

    private function value(array $row, array $keys, mixed $default = ''): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];

            if (is_bool($value)) {
                return $value;
            }

            if (is_array($value) || is_object($value)) {
                return $value;
            }

            if (trim((string) ($value ?? '')) !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function combine(array $values): string
    {
        return collect($values)
            ->map(fn ($value) => trim((string) ($value ?? '')))
            ->filter()
            ->unique()
            ->join(' | ');
    }

    private function yesNo(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $string = strtolower(trim((string) ($value ?? '')));

        if (in_array($string, ['1', 'true', 'yes', 'y', 'needed', 'scheduled'], true)) {
            return 'Yes';
        }

        if (in_array($string, ['0', 'false', 'no', 'n', 'none', 'cancelled'], true)) {
            return 'No';
        }

        return $string !== '' ? $string : 'No';
    }

    private function rhuLabel(mixed $value): string
    {
        $string = trim((string) ($value ?? ''));

        if ($string === '') {
            return '';
        }

        if (str_starts_with(strtoupper($string), 'RHU')) {
            return $string;
        }

        return 'RHU ' . $string;
    }

    private function dohAgeGroup(mixed $age): string
    {
        if (!is_numeric($age)) {
            return '';
        }

        $age = (int) $age;

        return match (true) {
            $age < 1 => 'Under 1',
            $age <= 5 => '1-5',
            $age <= 12 => '6-12',
            $age <= 17 => '13-17',
            $age <= 59 => '18-59',
            default => '60+',
        };
    }

    private function pediatricLabel(mixed $age): string
    {
        if (!is_numeric($age)) {
            return '';
        }

        return ((int) $age <= 2) ? 'Yes' : 'No';
    }

    private function csvCell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return trim((string) ($value ?? ''));
    }
}
