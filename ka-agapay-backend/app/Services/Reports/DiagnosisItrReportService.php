<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DiagnosisItrReportService
{
    /**
     * Return completed consultations with Diagnosis + ITR fields ready for web tables / CSV.
     */
    public function rows(array $filters = [], mixed $viewer = null): Collection
    {
        if (!Schema::hasTable('consultations')) {
            return collect();
        }

        $query = DB::table('consultations as c');

        $this->joinUsers($query, 'u', 'c.user_id');
        $this->joinResidentProfiles($query);
        $this->joinBarangays($query);
        $this->joinAppointments($query);
        $this->joinLatestQueueTicket($query);
        $this->joinUsers($query, 'staff', 'c.attended_by');
        $this->joinLatestFollowUp($query);

        $dateColumn = $this->dateColumnForConsultations();

        $query
            ->whereRaw("LOWER(COALESCE(c.status, '')) = 'completed'")
            ->when($filters['date_from'] ?? $filters['from'] ?? null, function ($q, $from) use ($dateColumn) {
                $q->where("c.{$dateColumn}", '>=', Carbon::parse($from)->startOfDay());
            })
            ->when($filters['date_to'] ?? $filters['to'] ?? null, function ($q, $to) use ($dateColumn) {
                $q->where("c.{$dateColumn}", '<=', Carbon::parse($to)->endOfDay());
            });

        $this->applyRhuScope($query, $filters, $viewer);
        $this->applyBarangayFilter($query, $filters);
        $this->applyDiagnosisFilter($query, $filters);

        $query->select($this->selects());
        $query->orderByDesc($this->orderColumnExpression());
        $query->orderByDesc('c.id');

        return $query->get()
            ->map(fn ($row) => $this->normaliseRow($row))
            ->values();
    }

    public function summary(array $filters = [], mixed $viewer = null): array
    {
        $rows = $this->rows($filters, $viewer);

        $diagnosed = $rows->filter(fn ($row) => $this->filled($row['diagnosis'] ?? null));

        $diagnosisCounts = $diagnosed
            ->groupBy(fn ($row) => $this->normaliseLabel($row['diagnosis'] ?? 'Unspecified'))
            ->map(fn ($items, $diagnosis) => [
                'diagnosis' => $diagnosis,
                'total' => $items->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $barangayDiagnosisCounts = $diagnosed
            ->groupBy(fn ($row) => $this->normaliseLabel($row['barangay'] ?? 'Unspecified') . '||' . $this->normaliseLabel($row['diagnosis'] ?? 'Unspecified'))
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'barangay' => $first['barangay'] ?? 'Unspecified',
                    'diagnosis' => $this->normaliseLabel($first['diagnosis'] ?? 'Unspecified'),
                    'total' => $items->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $ageSexBreakdown = $rows
            ->groupBy(function ($row) {
                $age = is_numeric($row['age'] ?? null) ? (int) $row['age'] : null;
                $ageGroup = match (true) {
                    $age === null => 'Unknown age',
                    $age < 1 => 'Under 1',
                    $age <= 5 => '1-5',
                    $age <= 12 => '6-12',
                    $age <= 17 => '13-17',
                    $age <= 59 => '18-59',
                    default => '60+',
                };

                $sex = $this->normaliseLabel($row['sex_gender'] ?? 'Unspecified');

                return $ageGroup . '||' . $sex;
            })
            ->map(function ($items, $key) {
                [$ageGroup, $sex] = explode('||', $key, 2);

                return [
                    'age_group' => $ageGroup,
                    'sex_gender' => $sex,
                    'total' => $items->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return [
            'total_completed_consultations' => $rows->count(),
            'total_diagnosed_consultations' => $diagnosed->count(),
            'top_diagnosis' => $diagnosisCounts->first()['diagnosis'] ?? null,
            'barangays_with_diagnosed_cases' => $diagnosed
                ->pluck('barangay')
                ->map(fn ($value) => $this->normaliseLabel($value ?: 'Unspecified'))
                ->unique()
                ->values()
                ->count(),
            'followups_scheduled' => $rows
                ->filter(fn ($row) => (bool) ($row['follow_up_needed'] ?? false))
                ->count(),
            'diagnosis_counts' => $diagnosisCounts->all(),
            'barangay_diagnosis_counts' => $barangayDiagnosisCounts->all(),
            'age_sex_breakdown' => $ageSexBreakdown->all(),
            'recent_diagnosis_itr_cases' => $rows
                ->take(15)
                ->map(fn ($row) => [
                    'consultation_id' => $row['consultation_id'],
                    'patient_name' => $row['patient_name'],
                    'age' => $row['age'],
                    'sex_gender' => $row['sex_gender'],
                    'barangay' => $row['barangay'],
                    'diagnosis' => $row['diagnosis'],
                    'treatment' => $row['treatment'],
                    'consultation_date' => $row['consultation_date'],
                    'completed_at' => $row['completed_at'],
                    'follow_up_status' => $row['follow_up_status'],
                    'attending_staff' => $row['attending_staff'],
                ])
                ->values()
                ->all(),
        ];
    }

    public function heatmapSignals(array $filters = [], mixed $viewer = null): Collection
    {
        $rows = $this->rows($filters, $viewer);
        $now = now();

        $countMap = $rows
            ->groupBy(function ($row) {
                $barangay = $this->normaliseLabel($row['barangay'] ?? 'Unspecified');
                $signal = $this->normaliseLabel($this->diagnosisOrSignal($row));

                return mb_strtolower($barangay . '||' . $signal);
            })
            ->map(fn ($items) => $items->count());

        return $rows
            ->map(function ($row) use ($countMap, $now) {
                $signal = $this->diagnosisOrSignal($row);
                $key = mb_strtolower($this->normaliseLabel($row['barangay'] ?? 'Unspecified') . '||' . $this->normaliseLabel($signal));
                $caseCount = (int) ($countMap[$key] ?? 1);

                $freshUntil = $row['heatmap_signal_expires_at'] ?: null;
                $expires = $freshUntil ? $this->safeCarbon($freshUntil) : null;
                $isFresh = $expires ? $expires->greaterThanOrEqualTo($now) : false;

                return [
                    'consultation_id' => $row['consultation_id'],
                    'barangay' => $row['barangay'],
                    'rhu_id' => $row['rhu_id'],
                    'risk' => $this->riskLevel($caseCount),
                    'diagnosis_or_signal' => $signal,
                    'case_count' => $caseCount,
                    'patient_name' => $row['patient_name'],
                    'age' => $row['age'],
                    'sex_gender' => $row['sex_gender'],
                    'consultation_date' => $row['consultation_date'],
                    'completed_at' => $row['completed_at'],
                    'heatmap_posted_at' => $row['heatmap_posted_at'] ?: null,
                    'heatmap_signal_expires_at' => $row['heatmap_signal_expires_at'] ?: null,
                    'is_fresh_signal' => $isFresh,
                    'fresh_until' => $freshUntil,
                    'historical_status' => $isFresh
                        ? 'Fresh within 3 hours'
                        : 'Historical expired signal',
                ];
            })
            ->sortByDesc('case_count')
            ->sortByDesc(fn ($row) => $row['is_fresh_signal'] ? 1 : 0)
            ->values();
    }

    private function selects(): array
    {
        return [
            'c.id as consultation_id',
            $this->selectColumn('consultations', 'c', 'consultation_date', 'consultation_date'),
            $this->selectColumn('consultations', 'c', 'completed_at', 'completed_at'),
            $this->selectColumn('consultations', 'c', 'first_attended_at', 'first_attended_at'),
            $this->selectColumn('consultations', 'c', 'status', 'status'),
            $this->selectColumn('consultations', 'c', 'chief_complaint', 'chief_complaint'),
            $this->selectColumn('consultations', 'c', 'subjective', 'subjective'),
            $this->selectColumn('consultations', 'c', 'objective', 'objective'),
            $this->selectColumn('consultations', 'c', 'assessment', 'assessment'),
            $this->selectColumn('consultations', 'c', 'plan', 'plan'),
            $this->selectColumn('consultations', 'c', 'diagnosis', 'diagnosis'),
            $this->selectColumn('consultations', 'c', 'treatment', 'treatment'),
            $this->selectColumn('consultations', 'c', 'notes', 'notes'),
            $this->selectColumn('consultations', 'c', 'heatmap_posted_at', 'heatmap_posted_at'),
            $this->selectColumn('consultations', 'c', 'heatmap_signal_expires_at', 'heatmap_signal_expires_at'),

            // Vitals / RHU staff-filled clinical fields
            $this->selectColumn('consultations', 'c', 'vital_signs', 'vital_signs'),
            $this->selectColumn('consultations', 'c', 'weight', 'weight'),
            $this->selectColumn('consultations', 'c', 'bmi', 'bmi'),
            $this->selectColumn('consultations', 'c', 'temperature_celsius', 'temperature_celsius'),
            $this->selectColumn('consultations', 'c', 'blood_pressure', 'blood_pressure'),
            $this->selectColumn('consultations', 'c', 'spo2', 'spo2'),
            $this->selectColumn('consultations', 'c', 'heart_rate', 'heart_rate'),
            $this->selectColumn('consultations', 'c', 'visual_acuity', 'visual_acuity'),
            $this->selectColumn('consultations', 'c', 'visual_acuity_left', 'visual_acuity_left'),
            $this->selectColumn('consultations', 'c', 'visual_acuity_right', 'visual_acuity_right'),
            $this->selectColumn('consultations', 'c', 'pediatric_client', 'pediatric_client'),
            $this->selectColumn('consultations', 'c', 'length_cm', 'length_cm'),
            $this->selectColumn('consultations', 'c', 'head_circumference_cm', 'head_circumference_cm'),
            $this->selectColumn('consultations', 'c', 'skinfold_thickness_cm', 'skinfold_thickness_cm'),
            $this->selectColumn('consultations', 'c', 'waist_cm', 'waist_cm'),
            $this->selectColumn('consultations', 'c', 'hip_cm', 'hip_cm'),
            $this->selectColumn('consultations', 'c', 'limbs_cm', 'limbs_cm'),
            $this->selectColumn('consultations', 'c', 'muac_cm', 'muac_cm'),
            $this->selectColumn('consultations', 'c', 'general_survey', 'general_survey'),
            $this->selectColumn('consultations', 'c', 'awake_and_alert', 'awake_and_alert'),
            $this->selectColumn('consultations', 'c', 'altered_sensorium', 'altered_sensorium'),
            $this->selectColumn('consultations', 'c', 'prescribed_drugs', 'prescribed_drugs'),

            $this->selectColumn('appointments', 'a', 'rhu_id', 'appointment_rhu_id'),
            $this->selectColumn('appointments', 'a', 'consultation_type', 'appointment_type'),
            $this->selectColumn('appointments', 'a', 'reason', 'appointment_reason'),
            $this->selectColumn('appointments', 'a', 'purpose', 'appointment_purpose'),
            $this->selectColumn('appointments', 'a', 'symptoms', 'appointment_symptoms'),

            $this->selectFirstAvailableColumn('queue_tickets', 'qt', ['queue_number', 'ticket_number', 'number'], 'queue_number'),
            $this->selectFirstAvailableColumn('queue_tickets', 'qt', ['queue_source', 'source', 'service_type'], 'queue_source'),

            $this->selectColumn('users', 'u', 'user_id', 'user_id'),
            $this->selectColumn('users', 'u', 'first_name', 'user_first_name'),
            $this->selectColumn('users', 'u', 'last_name', 'user_last_name'),
            $this->selectColumn('users', 'u', 'mobile_number', 'user_mobile_number'),
            $this->selectColumn('users', 'u', 'sex', 'user_sex'),
            $this->selectColumn('users', 'u', 'birthday', 'user_birthday'),
            $this->selectColumn('users', 'u', 'barangay', 'user_barangay'),
            $this->selectColumn('users', 'u', 'barangay_id', 'user_barangay_id'),

            $this->selectColumn('resident_profiles', 'rp', 'first_name', 'profile_first_name'),
            $this->selectColumn('resident_profiles', 'rp', 'middle_name', 'profile_middle_name'),
            $this->selectColumn('resident_profiles', 'rp', 'last_name', 'profile_last_name'),
            $this->selectColumn('resident_profiles', 'rp', 'suffix', 'profile_suffix'),
            $this->selectColumn('resident_profiles', 'rp', 'sex', 'profile_sex'),
            $this->selectColumn('resident_profiles', 'rp', 'gender', 'profile_gender'),
            $this->selectColumn('resident_profiles', 'rp', 'birth_date', 'profile_birth_date'),
            $this->selectColumn('resident_profiles', 'rp', 'birthdate', 'profile_birthdate'),
            $this->selectColumn('resident_profiles', 'rp', 'date_of_birth', 'profile_date_of_birth'),
            $this->selectColumn('resident_profiles', 'rp', 'barangay_id', 'profile_barangay_id'),
            $this->selectColumn('resident_profiles', 'rp', 'address', 'profile_address'),
            $this->selectColumn('resident_profiles', 'rp', 'street', 'profile_street'),
            $this->selectColumn('resident_profiles', 'rp', 'purok', 'profile_purok'),
            $this->selectColumn('resident_profiles', 'rp', 'mobile_number', 'profile_mobile_number'),
            $this->selectColumn('resident_profiles', 'rp', 'contact_number', 'profile_contact_number'),
            $this->selectColumn('resident_profiles', 'rp', 'phone_number', 'profile_phone_number'),
            $this->selectColumn('resident_profiles', 'rp', 'guardian_name', 'guardian_name'),
            $this->selectColumn('resident_profiles', 'rp', 'emergency_contact_name', 'emergency_contact_name'),
            $this->selectColumn('resident_profiles', 'rp', 'emergency_contact_number', 'guardian_contact'),
            $this->selectColumn('resident_profiles', 'rp', 'philhealth_no', 'philhealth_no'),
            $this->selectColumn('resident_profiles', 'rp', 'philhealth_number', 'philhealth_number'),
            $this->selectColumn('resident_profiles', 'rp', 'philhealth_pin', 'philhealth_pin'),
            $this->selectColumn('resident_profiles', 'rp', 'philhealth_verified_at', 'philhealth_verified_at'),

            // ITR demographic / lifestyle / history (patient-reported)
            $this->selectColumn('resident_profiles', 'rp', 'civil_status', 'civil_status'),
            $this->selectColumn('resident_profiles', 'rp', 'religion', 'religion'),
            $this->selectColumn('resident_profiles', 'rp', 'educational_attainment', 'educational_attainment'),
            $this->selectColumn('resident_profiles', 'rp', 'blood_type', 'blood_type'),
            $this->selectColumn('resident_profiles', 'rp', 'guardian_birthdate', 'guardian_birthdate'),
            $this->selectColumn('resident_profiles', 'rp', 'smoking_status', 'smoking_status'),
            $this->selectColumn('resident_profiles', 'rp', 'alcohol_intake', 'alcohol_intake'),
            $this->selectColumn('resident_profiles', 'rp', 'personal_social_history', 'personal_social_history'),
            $this->selectColumn('resident_profiles', 'rp', 'past_medical_history', 'past_medical_history'),
            $this->selectColumn('resident_profiles', 'rp', 'medical_history', 'medical_history'),
            $this->selectColumn('resident_profiles', 'rp', 'allergies', 'allergies'),
            $this->selectColumn('resident_profiles', 'rp', 'maintenance_medications', 'maintenance_medications'),

            // Female-specific ITR fields
            $this->selectColumn('resident_profiles', 'rp', 'number_of_children', 'number_of_children'),
            $this->selectColumn('resident_profiles', 'rp', 'lmp', 'lmp'),
            $this->selectColumn('resident_profiles', 'rp', 'period_duration', 'period_duration'),
            $this->selectColumn('resident_profiles', 'rp', 'cycle', 'cycle'),
            $this->selectColumn('resident_profiles', 'rp', 'family_planning_method', 'family_planning_method'),
            $this->selectColumn('resident_profiles', 'rp', 'menopausal_age', 'menopausal_age'),

            $this->selectFirstAvailableColumn('barangays', 'b', ['name', 'barangay_name'], 'barangay_name'),

            $this->selectColumn('follow_up_reminders', 'fu', 'id', 'follow_up_id'),
            $this->selectColumn('follow_up_reminders', 'fu', 'follow_up_at', 'follow_up_at'),
            $this->selectColumn('follow_up_reminders', 'fu', 'follow_up_date', 'follow_up_date'),
            $this->selectColumn('follow_up_reminders', 'fu', 'follow_up_time', 'follow_up_time'),
            $this->selectColumn('follow_up_reminders', 'fu', 'instructions', 'follow_up_instructions'),
            $this->selectColumn('follow_up_reminders', 'fu', 'status', 'follow_up_status'),

            $this->selectColumn('users', 'staff', 'user_id', 'staff_user_id'),
            $this->selectColumn('users', 'staff', 'first_name', 'staff_first_name'),
            $this->selectColumn('users', 'staff', 'last_name', 'staff_last_name'),
        ];
    }

    private function normaliseRow(object $row): array
    {
        $birthdate = $this->firstFilled([
            $row->profile_birth_date ?? null,
            $row->profile_birthdate ?? null,
            $row->profile_date_of_birth ?? null,
            $row->user_birthday ?? null,
        ]);

        $consultationDate = $this->dateString($this->firstFilled([
            $row->consultation_date ?? null,
            $row->completed_at ?? null,
        ]));

        $patientName = $this->personName([
            $row->profile_first_name ?? null,
            $row->profile_middle_name ?? null,
            $row->profile_last_name ?? null,
            $row->profile_suffix ?? null,
        ]);

        if (!$patientName) {
            $patientName = $this->personName([
                $row->user_first_name ?? null,
                $row->user_last_name ?? null,
            ]);
        }

        if (!$patientName) {
            $patientName = 'Patient #' . ($row->user_id ?? '—');
        }

        $address = $this->firstFilled([
            $row->profile_address ?? null,
            trim(implode(' ', array_filter([
                $row->profile_purok ?? null,
                $row->profile_street ?? null,
            ]))),
        ]);

        $appointmentReason = $this->firstFilled([
            $row->appointment_reason ?? null,
            $row->appointment_purpose ?? null,
            $row->appointment_symptoms ?? null,
        ]);

        $followUpDateTime = $this->firstFilled([
            $row->follow_up_at ?? null,
            trim((string) ($row->follow_up_date ?? '') . ' ' . (string) ($row->follow_up_time ?? '')),
        ]);

        $followUpStatus = $this->filled($row->follow_up_status ?? null)
            ? (string) $row->follow_up_status
            : 'none';

        $followUpNeeded = $this->filled($row->follow_up_id ?? null)
            && !in_array(Str::lower($followUpStatus), ['cancelled', 'none'], true);

        return [
            'consultation_id' => (int) $row->consultation_id,
            'consultation_date' => $consultationDate,
            'completed_at' => $this->dateTimeString($row->completed_at ?? null),
            'first_attended_at' => $this->dateTimeString($row->first_attended_at ?? null),
            'status' => (string) ($row->status ?? 'completed'),
            'rhu_id' => $this->intOrNull($row->appointment_rhu_id ?? null),
            'queue_number' => $this->nullIfBlank($row->queue_number ?? null),
            'queue_source' => $this->nullIfBlank($row->queue_source ?? null),
            'appointment_type' => $this->nullIfBlank($row->appointment_type ?? null),
            'patient_name' => $patientName,
            'age' => $this->age($birthdate, $consultationDate),
            'sex_gender' => $this->firstFilled([
                $row->profile_sex ?? null,
                $row->profile_gender ?? null,
                $row->user_sex ?? null,
            ]) ?: null,
            'birthdate' => $this->dateString($birthdate),
            'barangay' => $this->firstFilled([
                $row->barangay_name ?? null,
                $row->user_barangay ?? null,
                $row->profile_barangay_id ? 'Barangay #' . $row->profile_barangay_id : null,
            ]) ?: 'Unspecified',
            'address' => $this->nullIfBlank($address),
            'mobile_number' => $this->firstFilled([
                $row->profile_mobile_number ?? null,
                $row->profile_contact_number ?? null,
                $row->profile_phone_number ?? null,
                $row->user_mobile_number ?? null,
            ]),
            'guardian_name' => $this->firstFilled([
                $row->guardian_name ?? null,
                $row->emergency_contact_name ?? null,
            ]),
            'guardian_contact' => $this->nullIfBlank($row->guardian_contact ?? null),
            'philhealth_id' => $this->firstFilled([
                $row->philhealth_number ?? null,
                $row->philhealth_no ?? null,
                $row->philhealth_pin ?? null,
            ]),
            'philhealth_verified' => $this->filled($row->philhealth_verified_at ?? null) ? 'Yes' : 'No',
            'appointment_reason' => $this->nullIfBlank($appointmentReason),
            'chief_complaint' => $this->nullIfBlank($row->chief_complaint ?? null),
            'subjective' => $this->nullIfBlank($row->subjective ?? null),
            'objective' => $this->nullIfBlank($row->objective ?? null),
            'assessment' => $this->nullIfBlank($row->assessment ?? null),
            'plan' => $this->nullIfBlank($row->plan ?? null),
            'diagnosis' => $this->nullIfBlank($row->diagnosis ?? null),
            'treatment' => $this->nullIfBlank($row->treatment ?? null),
            'notes' => $this->nullIfBlank($row->notes ?? null),
            'follow_up_needed' => $followUpNeeded,
            'follow_up_date_time' => $this->dateTimeString($followUpDateTime),
            'follow_up_instructions' => $this->nullIfBlank($row->follow_up_instructions ?? null),
            'follow_up_status' => $followUpStatus,
            'attending_staff' => $this->personName([
                $row->staff_first_name ?? null,
                $row->staff_last_name ?? null,
            ]) ?: (($row->staff_user_id ?? null) ? 'RHU Staff #' . $row->staff_user_id : null),
            'heatmap_posted_at' => $this->dateTimeString($row->heatmap_posted_at ?? null),
            'heatmap_signal_expires_at' => $this->dateTimeString($row->heatmap_signal_expires_at ?? null),

            // ITR demographic / lifestyle / history (patient-reported)
            'civil_status' => $this->nullIfBlank($row->civil_status ?? null),
            'religion' => $this->nullIfBlank($row->religion ?? null),
            'educational_attainment' => $this->nullIfBlank($row->educational_attainment ?? null),
            'blood_type' => $this->nullIfBlank($row->blood_type ?? null),
            'guardian_birthdate' => $this->dateString($row->guardian_birthdate ?? null),
            'smoking' => $this->nullIfBlank($row->smoking_status ?? null),
            'alcohol_intake' => $this->nullIfBlank($row->alcohol_intake ?? null),
            'personal_social_history' => $this->nullIfBlank($row->personal_social_history ?? null),
            'past_medical_history' => $this->firstFilled([
                $row->past_medical_history ?? null,
                $row->medical_history ?? null,
            ]),
            'allergies' => $this->nullIfBlank($row->allergies ?? null),
            'maintenance_medications' => $this->nullIfBlank($row->maintenance_medications ?? null),

            // Female-specific ITR fields
            'number_of_children' => $this->nullIfBlank($row->number_of_children ?? null),
            'lmp' => $this->dateString($row->lmp ?? null),
            'period_duration' => $this->nullIfBlank($row->period_duration ?? null),
            'cycle' => $this->nullIfBlank($row->cycle ?? null),
            'family_planning_method' => $this->nullIfBlank($row->family_planning_method ?? null),
            'menopausal_age' => $this->nullIfBlank($row->menopausal_age ?? null),

            // Vitals / RHU staff-filled
            'vital_signs' => $this->nullIfBlank($row->vital_signs ?? null),
            'weight' => $this->nullIfBlank($row->weight ?? null),
            'bmi' => $this->nullIfBlank($row->bmi ?? null),
            'temperature_celsius' => $this->nullIfBlank($row->temperature_celsius ?? null),
            'blood_pressure' => $this->nullIfBlank($row->blood_pressure ?? null),
            'spo2' => $this->nullIfBlank($row->spo2 ?? null),
            'heart_rate' => $this->nullIfBlank($row->heart_rate ?? null),
            'visual_acuity' => $this->nullIfBlank($row->visual_acuity ?? null),
            'visual_acuity_left' => $this->nullIfBlank($row->visual_acuity_left ?? null),
            'visual_acuity_right' => $this->nullIfBlank($row->visual_acuity_right ?? null),

            // Pediatric client
            'pediatric_client' => $this->boolText($row->pediatric_client ?? null),
            'length_cm' => $this->nullIfBlank($row->length_cm ?? null),
            'head_circumference_cm' => $this->nullIfBlank($row->head_circumference_cm ?? null),
            'skinfold_thickness_cm' => $this->nullIfBlank($row->skinfold_thickness_cm ?? null),
            'waist_cm' => $this->nullIfBlank($row->waist_cm ?? null),
            'hip_cm' => $this->nullIfBlank($row->hip_cm ?? null),
            'limbs_cm' => $this->nullIfBlank($row->limbs_cm ?? null),
            'muac_cm' => $this->nullIfBlank($row->muac_cm ?? null),

            // General survey
            'general_survey' => $this->nullIfBlank($row->general_survey ?? null),
            'awake_and_alert' => $this->boolText($row->awake_and_alert ?? null),
            'altered_sensorium' => $this->boolText($row->altered_sensorium ?? null),

            // Prescription summary
            'prescribed_drugs' => $this->nullIfBlank($row->prescribed_drugs ?? null),
        ];
    }

    private function boolText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    private function applyRhuScope($query, array $filters, mixed $viewer): void
    {
        $rhuExpr = $this->rhuExpression();
        $requestedRhu = $filters['rhu_id'] ?? null;

        $isGlobal = $viewer && method_exists($viewer, 'isGlobalRhuScope')
            ? (bool) $viewer->isGlobalRhuScope()
            : false;

        $viewerRhu = $viewer && method_exists($viewer, 'effectiveRhuId')
            ? $viewer->effectiveRhuId()
            : null;

        if (!$isGlobal && $viewerRhu) {
            $query->whereRaw("{$rhuExpr} = ?", [(int) $viewerRhu]);
            return;
        }

        if ($requestedRhu !== null && $requestedRhu !== '' && $requestedRhu !== 'all') {
            $query->whereRaw("{$rhuExpr} = ?", [(int) $requestedRhu]);
        }
    }

    private function applyBarangayFilter($query, array $filters): void
    {
        $barangayId = $filters['barangay_id'] ?? null;

        if ($barangayId === null || $barangayId === '' || $barangayId === 'all') {
            return;
        }

        if (Schema::hasTable('resident_profiles') && Schema::hasColumn('resident_profiles', 'barangay_id')) {
            $query->where('rp.barangay_id', (int) $barangayId);
        } elseif (Schema::hasTable('users') && Schema::hasColumn('users', 'barangay_id')) {
            $query->where('u.barangay_id', (int) $barangayId);
        }
    }

    private function applyDiagnosisFilter($query, array $filters): void
    {
        $needle = trim((string) ($filters['diagnosis'] ?? $filters['disease'] ?? ''));

        if ($needle === '') {
            return;
        }

        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        $query->where(function ($inner) use ($needle, $like) {
            foreach (['diagnosis', 'chief_complaint', 'assessment', 'treatment', 'subjective', 'plan'] as $column) {
                if (Schema::hasColumn('consultations', $column)) {
                    $inner->orWhere("c.{$column}", $like, "%{$needle}%");
                }
            }

            if (Schema::hasTable('appointments')) {
                foreach (['reason', 'purpose', 'symptoms'] as $column) {
                    if (Schema::hasColumn('appointments', $column)) {
                        $inner->orWhere("a.{$column}", $like, "%{$needle}%");
                    }
                }
            }
        });
    }

    private function joinUsers($query, string $alias, string $leftColumn): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'user_id')) {
            $query->leftJoin("users as {$alias}", "{$alias}.user_id", '=', $leftColumn);
        }
    }

    private function joinResidentProfiles($query): void
    {
        if (
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('resident_profiles', 'user_id')
        ) {
            $query->leftJoin('resident_profiles as rp', 'rp.user_id', '=', 'c.user_id');
        }
    }

    private function joinBarangays($query): void
    {
        if (
            Schema::hasTable('barangays') &&
            Schema::hasTable('resident_profiles') &&
            Schema::hasColumn('barangays', 'barangay_id') &&
            Schema::hasColumn('resident_profiles', 'barangay_id')
        ) {
            $query->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id');
        }
    }

    private function joinAppointments($query): void
    {
        if (
            Schema::hasTable('appointments') &&
            Schema::hasColumn('consultations', 'appointment_id')
        ) {
            $query->leftJoin('appointments as a', 'a.id', '=', 'c.appointment_id');
        }
    }

    private function joinLatestQueueTicket($query): void
    {
        if (!Schema::hasTable('queue_tickets')) {
            return;
        }

        if (Schema::hasColumn('queue_tickets', 'appointment_id') && Schema::hasTable('appointments')) {
            $latest = DB::table('queue_tickets')
                ->selectRaw('MAX(id) as id, appointment_id')
                ->whereNotNull('appointment_id')
                ->groupBy('appointment_id');

            $query->leftJoinSub($latest, 'latest_qt', 'latest_qt.appointment_id', '=', 'a.id')
                ->leftJoin('queue_tickets as qt', 'qt.id', '=', 'latest_qt.id');

            return;
        }

        if (Schema::hasColumn('queue_tickets', 'consultation_id')) {
            $latest = DB::table('queue_tickets')
                ->selectRaw('MAX(id) as id, consultation_id')
                ->whereNotNull('consultation_id')
                ->groupBy('consultation_id');

            $query->leftJoinSub($latest, 'latest_qt', 'latest_qt.consultation_id', '=', 'c.id')
                ->leftJoin('queue_tickets as qt', 'qt.id', '=', 'latest_qt.id');
        }
    }

    private function joinLatestFollowUp($query): void
    {
        if (!Schema::hasTable('follow_up_reminders') || !Schema::hasColumn('follow_up_reminders', 'consultation_id')) {
            return;
        }

        $latest = DB::table('follow_up_reminders')
            ->selectRaw('MAX(id) as id, consultation_id')
            ->whereNotNull('consultation_id')
            ->groupBy('consultation_id');

        $query->leftJoinSub($latest, 'latest_fu', 'latest_fu.consultation_id', '=', 'c.id')
            ->leftJoin('follow_up_reminders as fu', 'fu.id', '=', 'latest_fu.id');
    }

    private function dateColumnForConsultations(): string
    {
        foreach (['consultation_date', 'completed_at', 'created_at'] as $column) {
            if (Schema::hasColumn('consultations', $column)) {
                return $column;
            }
        }

        return 'id';
    }

    private function orderColumnExpression(): string
    {
        if (Schema::hasColumn('consultations', 'completed_at')) {
            return 'c.completed_at';
        }

        if (Schema::hasColumn('consultations', 'consultation_date')) {
            return 'c.consultation_date';
        }

        return 'c.id';
    }

    private function rhuExpression(): string
    {
        $parts = [];

        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'rhu_id')) {
            $parts[] = 'a.rhu_id';
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'assigned_rhu_id')) {
            $parts[] = 'staff.assigned_rhu_id';
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'barangay_id')) {
            $parts[] = 'staff.barangay_id';
        }

        if (Schema::hasTable('resident_profiles') && Schema::hasColumn('resident_profiles', 'barangay_id')) {
            $parts[] = 'rp.barangay_id';
        }

        if (count($parts) === 0) {
            return 'NULL';
        }

        return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function selectColumn(string $table, string $alias, string $column, string $as): mixed
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            return "{$alias}.{$column} as {$as}";
        }

        return DB::raw("NULL as {$as}");
    }

    private function selectFirstAvailableColumn(string $table, string $alias, array $columns, string $as): mixed
    {
        foreach ($columns as $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                return "{$alias}.{$column} as {$as}";
            }
        }

        return DB::raw("NULL as {$as}");
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $string = trim((string) ($value ?? ''));

            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }

    private function filled(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        return $string === '' ? null : $string;
    }

    private function personName(array $parts): ?string
    {
        $name = collect($parts)
            ->map(fn ($part) => trim((string) ($part ?? '')))
            ->filter()
            ->join(' ');

        return $name !== '' ? preg_replace('/\s+/', ' ', $name) : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function age(?string $birthdate, ?string $asOf): ?int
    {
        if (!$birthdate) {
            return null;
        }

        try {
            $birth = Carbon::parse($birthdate);
            $date = $asOf ? Carbon::parse($asOf) : now();
            $age = $birth->diffInYears($date);

            return $age >= 0 && $age <= 130 ? $age : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateString(mixed $value): ?string
    {
        if (!$this->filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return trim((string) $value) ?: null;
        }
    }

    private function dateTimeString(mixed $value): ?string
    {
        if (!$this->filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return trim((string) $value) ?: null;
        }
    }

    private function safeCarbon(mixed $value): ?Carbon
    {
        if (!$this->filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function diagnosisOrSignal(array $row): string
    {
        return $this->firstFilled([
            $row['diagnosis'] ?? null,
            $row['chief_complaint'] ?? null,
            $row['appointment_reason'] ?? null,
        ]) ?: 'General Health Signal';
    }

    private function normaliseLabel(mixed $value): string
    {
        $label = preg_replace('/\s+/', ' ', trim((string) ($value ?? ''))) ?: 'Unspecified';
        return mb_strlen($label) > 120 ? mb_substr($label, 0, 120) . '…' : $label;
    }

    private function riskLevel(int $caseCount): string
    {
        return match (true) {
            $caseCount >= 10 => 'critical',
            $caseCount >= 5 => 'high',
            $caseCount >= 2 => 'moderate',
            default => 'low',
        };
    }
}
