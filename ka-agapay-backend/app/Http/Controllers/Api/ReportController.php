<?php
// app/Http/Controllers/Api/ReportController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\FollowUpReminder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * GET /api/v1/reports/consultations/export
     *
     * One CSV combining consultation/diagnosis + patient ITR + follow-up.
     * RHU-scoped: staff get their RHU only; super_admin/mho get all (or a chosen
     * rhu_id). Filters: date_from, date_to, rhu_id, barangay_id, diagnosis.
     */
    public function exportConsultationsCsv(Request $request): StreamedResponse
    {
        $user = $request->user();

        $query = Consultation::query()
            ->with([
                'resident.residentProfile.barangay',
                'appointment.queueTicket',
                'attendant',
            ]);

        if ($request->filled('date_from')) {
            $query->whereDate('consultation_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('consultation_date', '<=', $request->query('date_to'));
        }

        if ($request->filled('diagnosis')) {
            $d = trim((string) $request->query('diagnosis'));
            $query->where(function ($q) use ($d) {
                $q->where('diagnosis', 'like', "%{$d}%")
                    ->orWhere('assessment', 'like', "%{$d}%")
                    ->orWhere('chief_complaint', 'like', "%{$d}%");
            });
        }

        // RHU scoping (rhu_id lives on the appointment).
        $scopeRhu = null;
        if ($user && !$user->isGlobalRhuScope()) {
            $scopeRhu = (int) ($user->effectiveRhuId() ?? 0);
        } elseif ($request->filled('rhu_id')) {
            $scopeRhu = $request->integer('rhu_id');
        }

        if ($scopeRhu) {
            $query->whereHas('appointment', fn ($q) => $q->where('rhu_id', $scopeRhu));
        }

        if ($request->filled('barangay_id')) {
            $bid = $request->integer('barangay_id');
            $query->whereHas('resident.residentProfile', fn ($q) => $q->where('barangay_id', $bid));
        }

        $consultations = $query
            ->orderBy('consultation_date')
            ->orderBy('id')
            ->limit(5000)
            ->get();

        $followups = FollowUpReminder::query()
            ->whereIn('consultation_id', $consultations->pluck('id')->filter()->all())
            ->get()
            ->keyBy('consultation_id');

        $filename = 'diagnosis_itr_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Consultation ID', 'Visit Date', 'Started At', 'First Attended At', 'Completed At',
            'RHU', 'Queue Number', 'Queue Source', 'Appointment Type',
            'Patient Full Name', 'Age', 'DOH Age Group', 'Sex/Gender', 'Birthdate',
            'Barangay', 'Address', 'Guardian Name', 'Guardian Contact', 'Mobile Number', 'PhilHealth/ID',
            'Appointment Reason/Complaint', 'Subjective', 'Objective', 'Assessment', 'Plan',
            'Diagnosis', 'Treatment', 'Additional Notes',
            'Follow-up Needed', 'Follow-up Date/Time', 'Follow-up Instructions',
            'Attending Staff', 'Status',
        ];

        $callback = function () use ($consultations, $followups, $headers) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($consultations as $c) {
                fputcsv($out, $this->buildRow($c, $followups->get($c->id)));
            }

            fclose($out);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function buildRow(Consultation $c, ?FollowUpReminder $f): array
    {
        $user = $c->resident;
        $profile = $user?->residentProfile;
        $appt = $c->appointment;
        $ticket = $appt?->queueTicket;

        $birth = $user?->birthday
            ?? $profile?->birth_date
            ?? $profile?->birthdate
            ?? $profile?->date_of_birth;

        $age = $this->ageYears($birth, $c->consultation_date);

        return [
            $c->id,
            optional($c->consultation_date)->format('Y-m-d') ?? '',
            optional($c->started_at)->format('Y-m-d H:i') ?? '',
            optional($c->first_attended_at)->format('Y-m-d H:i') ?? '',
            optional($c->completed_at)->format('Y-m-d H:i') ?? '',
            $appt?->rhu_id ? "RHU {$appt->rhu_id}" : '',
            $ticket?->ticket_number ?? '',
            $ticket?->source ?? ($appt?->consultation_type ?? ''),
            $appt?->consultation_type ?? '',
            $this->np($user?->full_name),
            $age !== null ? $age : '',
            $age !== null ? $this->dohAgeGroup($age, $birth, $c->consultation_date) : '',
            $this->np($profile?->sex ?? $profile?->gender ?? $user?->sex),
            $birth ? Carbon::parse($birth)->format('Y-m-d') : '',
            $this->np($profile?->barangay?->name ?? $user?->barangay),
            $this->np($profile?->address),
            $this->np($profile?->guardian_name),
            $this->np($profile?->emergency_contact_number),
            $this->np($user?->mobile_number ?? $profile?->mobile_number ?? $profile?->contact_number),
            $this->np($profile?->philhealth_number ?? $profile?->philhealth_no),
            $this->clean($appt?->reason ?? $c->chief_complaint),
            $this->clean($c->subjective),
            $this->clean($c->objective),
            $this->clean($c->assessment),
            $this->clean($c->plan),
            $this->clean($c->diagnosis),
            $this->clean($c->treatment),
            $this->clean($c->notes),
            $f ? 'Yes' : 'No',
            $f ? trim((optional($f->follow_up_date)->format('Y-m-d') ?? '') . ' ' . (substr((string) $f->follow_up_time, 0, 5))) : '',
            $f ? $this->clean($f->instructions) : '',
            $this->np($c->attendant?->full_name),
            $c->status,
        ];
    }

    private function ageYears($birth, $asOf): ?int
    {
        if (!$birth) {
            return null;
        }

        try {
            $b = Carbon::parse($birth);
            $ref = $asOf ? Carbon::parse($asOf) : now();
            $age = $b->diffInYears($ref);
            return ($age >= 0 && $age < 130) ? $age : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dohAgeGroup(int $ageYears, $birth, $asOf): string
    {
        // Under 1 year → use months.
        if ($ageYears < 1 && $birth) {
            try {
                $months = Carbon::parse($birth)->diffInMonths($asOf ? Carbon::parse($asOf) : now());
                return $months <= 11 ? '0-11 months' : '1-4 years';
            } catch (\Throwable) {
                return '0-11 months';
            }
        }

        return match (true) {
            $ageYears <= 4 => '1-4 years',
            $ageYears <= 9 => '5-9 years',
            $ageYears <= 14 => '10-14 years',
            $ageYears <= 19 => '15-19 years',
            $ageYears <= 24 => '20-24 years',
            $ageYears <= 29 => '25-29 years',
            $ageYears <= 34 => '30-34 years',
            $ageYears <= 39 => '35-39 years',
            $ageYears <= 44 => '40-44 years',
            $ageYears <= 49 => '45-49 years',
            $ageYears <= 54 => '50-54 years',
            $ageYears <= 59 => '55-59 years',
            $ageYears <= 64 => '60-64 years',
            default => '65+ years',
        };
    }

    private function np($value): string
    {
        $s = trim((string) ($value ?? ''));
        return $s !== '' ? $s : 'Not provided';
    }

    private function clean($value): string
    {
        // Flatten newlines so CSV cells stay clean in Excel.
        return trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')) ?? '');
    }
}
