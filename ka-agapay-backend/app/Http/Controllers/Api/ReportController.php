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
     * Existing frontend CSV export endpoint. Kept compatible with the old button.
     */
    public function exportDiagnosisItrCsv(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->diagnosisItr->rows($filters, $request->user());

        $headers = [
            'consultation_id',
            'consultation_date',
            'completed_at',
            'first_attended_at',
            'status',
            'rhu_id',
            'queue_number',
            'queue_source',
            'appointment_type',
            'patient_name',
            'age',
            'sex_gender',
            'birthdate',
            'barangay',
            'address',
            'mobile_number',
            'guardian_name',
            'guardian_contact',
            'philhealth_id',
            'appointment_reason',
            'chief_complaint',
            'subjective',
            'objective',
            'assessment',
            'plan',
            'diagnosis',
            'treatment',
            'notes',
            'follow_up_needed',
            'follow_up_date_time',
            'follow_up_instructions',
            'follow_up_status',
            'attending_staff',
        ];

        $filename = 'diagnosis_itr_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    fn ($key) => is_bool($row[$key] ?? null)
                        ? (($row[$key] ?? false) ? 'Yes' : 'No')
                        : ($row[$key] ?? ''),
                    $headers
                ));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
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
}
