<?php
// app/Http/Controllers/Api/PrescriptionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrescriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $query = DB::table('prescriptions as p')
            ->leftJoin('resident_profiles as rp', 'rp.id', '=', 'p.resident_profile_id')
            ->leftJoin('users as u', 'u.user_id', '=', 'rp.user_id')
            ->leftJoin('users as d', 'd.user_id', '=', 'p.prescribed_by')
            ->selectRaw("p.*, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as patient_name")
            ->selectRaw("CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, '')) as prescriber_name");

        if ($request->filled('status')) {
            $query->where('p.status', $request->query('status'));
        }

        if ($request->filled('consultation_id')) {
            $query->where('p.consultation_id', $request->integer('consultation_id'));
        }

        if ($request->filled('resident_profile_id')) {
            $query->where('p.resident_profile_id', $request->integer('resident_profile_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->where(function ($q) use ($search) {
                $q->where('p.prescription_number', 'ilike', "%{$search}%")
                    ->orWhere('p.diagnosis', 'ilike', "%{$search}%")
                    ->orWhere('p.medications', 'ilike', "%{$search}%")
                    ->orWhereRaw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) ILIKE ?", ["%{$search}%"]);
            });
        }

        $rows = $query
            ->latest('p.prescription_date')
            ->paginate($request->integer('per_page', 20));

        $rows->getCollection()->transform(fn ($row) => $this->formatPrescription($row));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $validated = $request->validate([
            'resident_profile_id'        => ['required', 'integer'],
            'consultation_id'            => ['nullable', 'integer'],
            'telemedicine_session_id'    => ['nullable', 'integer'],
            'rhu_id'                     => ['nullable', 'integer'],
            'diagnosis'                  => ['nullable', 'string', 'max:1000'],
            'diagnosis_code'             => ['nullable', 'string', 'max:20'],
            'medications'                => ['required', 'array', 'min:1'],
            'additional_instructions'    => ['nullable', 'string', 'max:2000'],
            'dispensing_notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $rhuId = (int) ($validated['rhu_id'] ?? $user->barangay_id ?? 1);
        $number = $this->nextPrescriptionNumber($rhuId);

        $pdfPath = $this->generateAndStorePdf($number, [
            'Prescription No' => $number,
            'Date' => now()->format('Y-m-d'),
            'Diagnosis' => $validated['diagnosis'] ?? 'Not specified',
            'Medicines' => $this->medicinesToText($validated['medications']),
            'Instructions' => $validated['additional_instructions'] ?? '',
            'Prescriber ID' => (string) ($user->user_id ?? $user->id),
        ]);

        $id = DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $validated['resident_profile_id'],
            'prescribed_by' => $user->user_id ?? $user->id,
            'consultation_id' => $validated['consultation_id'] ?? null,
            'telemedicine_session_id' => $validated['telemedicine_session_id'] ?? null,
            'prescription_number' => $number,
            'rhu_id' => $rhuId,
            'prescription_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'diagnosis' => $validated['diagnosis'] ?? null,
            'diagnosis_code' => $validated['diagnosis_code'] ?? null,
            'medications' => json_encode($validated['medications']),
            'has_controlled_substances' => collect($validated['medications'])
                ->contains(fn ($m) => (bool) ($m['is_controlled'] ?? false)),
            'additional_instructions' => $validated['additional_instructions'] ?? null,
            'dispensing_notes' => $validated['dispensing_notes'] ?? null,
            'status' => 'active',
            'file_path' => $pdfPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Prescription issued successfully.',
            'data' => $this->formatPrescription(
                DB::table('prescriptions')->where('id', $id)->first()
            ),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $row = DB::table('prescriptions')->where('id', $id)->first();
        abort_unless($row, 404, 'Prescription not found.');

        return response()->json([
            'data' => $this->formatPrescription($row),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $validated = $request->validate([
            'status'                  => ['nullable', 'string', 'max:30'],
            'diagnosis'               => ['nullable', 'string', 'max:1000'],
            'medications'             => ['nullable', 'array'],
            'additional_instructions' => ['nullable', 'string', 'max:2000'],
            'dispensing_notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $updates = collect($validated)
            ->map(fn ($value, $key) => $key === 'medications' ? json_encode($value) : $value)
            ->all();

        $updates['updated_at'] = now();

        DB::table('prescriptions')->where('id', $id)->update($updates);

        return $this->show($id);
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        DB::table('prescriptions')->where('id', $id)->update([
            'status' => 'cancelled',
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Prescription cancelled.',
        ]);
    }

    public function release(Request $request, int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $row = DB::table('prescriptions')->where('id', $id)->first();
        abort_unless($row, 404, 'Prescription not found.');

        $medications = $this->decodeMedications($row->medications ?? '[]');
        $number = $row->prescription_number ?? ('RX-' . $row->id);

        $pdfPath = $this->generateAndStorePdf($number, [
            'Prescription No' => $number,
            'Date' => $row->prescription_date ?? now()->format('Y-m-d'),
            'Valid Until' => $row->valid_until ?? '',
            'Patient' => $this->patientName((int) $row->resident_profile_id),
            'Diagnosis' => $row->diagnosis ?? 'Not specified',
            'Medicines' => $this->medicinesToText($medications),
            'Instructions' => $row->additional_instructions ?? '',
            'Released By' => (string) ($request->user()->full_name ?? $request->user()->user_id ?? $request->user()->id),
        ]);

        DB::table('prescriptions')->where('id', $id)->update([
            'file_path' => $pdfPath,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Prescription PDF released.',
            'data' => $this->formatPrescription(
                DB::table('prescriptions')->where('id', $id)->first()
            ),
        ]);
    }

    public function dispense(Request $request, int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $validated = $request->validate([
            'dispensing_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = DB::table('prescriptions')->where('id', $id)->first();
        abort_unless($row, 404, 'Prescription not found.');

        DB::table('prescriptions')->where('id', $id)->update([
            'status' => 'dispensed',
            'dispensed_at' => now(),
            'dispensed_by' => $request->user()->user_id ?? $request->user()->id,
            'dispensing_notes' => $validated['dispensing_notes'] ?? $row->dispensing_notes ?? null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Prescription marked as dispensed.',
            'data' => $this->formatPrescription(
                DB::table('prescriptions')->where('id', $id)->first()
            ),
        ]);
    }

    public function downloadPdf(int $id)
    {
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $row = DB::table('prescriptions')->where('id', $id)->first();
        abort_unless($row, 404, 'Prescription not found.');

        $path = $row->file_path;

        if ($path && Storage::disk('public')->exists($path)) {
            return response(Storage::disk('public')->get($path), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . ($row->prescription_number ?? 'prescription') . '.pdf"',
            ]);
        }

        $pdf = $this->buildPdf([
            'Prescription No' => $row->prescription_number ?? ('RX-' . $row->id),
            'Date' => $row->prescription_date ?? now()->format('Y-m-d'),
            'Diagnosis' => $row->diagnosis ?? 'Not specified',
            'Medicines' => $this->medicinesToText($this->decodeMedications($row->medications ?? '[]')),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="prescription-' . $row->id . '.pdf"',
        ]);
    }

    private function formatPrescription(object $row): array
    {
        $data = (array) $row;

        $data['medications'] = $this->decodeMedications($row->medications ?? '[]');
        $data['pdf_url'] = !empty($row->file_path)
            ? Storage::disk('public')->url($row->file_path)
            : null;
        $data['pdf_endpoint'] = url('/api/v1/prescriptions/' . $row->id . '/pdf');

        return $data;
    }

    private function decodeMedications(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nextPrescriptionNumber(int $rhuId): string
    {
        $prefix = 'RHU' . $rhuId . '-RX-' . now()->format('Y');
        $count = DB::table('prescriptions')
            ->where('prescription_number', 'like', $prefix . '%')
            ->count() + 1;

        return $prefix . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function medicinesToText(array $medicines): string
    {
        if (empty($medicines)) {
            return 'No medicines listed.';
        }

        return collect($medicines)->map(function ($med, $index) {
            if (is_string($med)) {
                return ($index + 1) . '. ' . $med;
            }

            return ($index + 1) . '. ' . ($med['name'] ?? 'Medicine')
                . (!empty($med['dosage']) ? ' - ' . $med['dosage'] : '')
                . (!empty($med['frequency']) ? ' - ' . $med['frequency'] : '')
                . (!empty($med['duration']) ? ' - ' . $med['duration'] : '')
                . (!empty($med['route']) ? ' - ' . $med['route'] : '')
                . (!empty($med['instructions']) ? ' - ' . $med['instructions'] : '');
        })->implode("\n");
    }

    private function patientName(int $residentProfileId): string
    {
        if (!Schema::hasTable('resident_profiles') || !Schema::hasTable('users')) {
            return 'Resident Profile #' . $residentProfileId;
        }

        $row = DB::table('resident_profiles as rp')
            ->leftJoin('users as u', 'u.user_id', '=', 'rp.user_id')
            ->where('rp.id', $residentProfileId)
            ->selectRaw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as name")
            ->first();

        return trim((string) ($row->name ?? '')) ?: 'Resident Profile #' . $residentProfileId;
    }

    private function generateAndStorePdf(string $number, array $fields): string
    {
        $path = 'prescriptions/manual/' . $number . '.pdf';

        Storage::disk('public')->put($path, $this->buildPdf($fields));

        return $path;
    }

    private function buildPdf(array $fields): string
    {
        $lines = [
            'Ka-Agapay E-Prescription',
            'RHU Malasiqui, Pangasinan',
            '',
        ];

        foreach ($fields as $key => $value) {
            $valueLines = preg_split('/\r?\n/', (string) $value) ?: [''];
            $lines[] = $key . ': ' . array_shift($valueLines);

            foreach ($valueLines as $extra) {
                $lines[] = '    ' . $extra;
            }

            $lines[] = '';
        }

        $lines[] = 'This document was generated by Ka-Agapay.';
        $lines[] = 'Please verify details with authorized RHU staff.';

        $stream = "BT\n/F1 12 Tf\n50 780 Td\n14 TL\n";

        foreach ($lines as $line) {
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::limit($line, 100, ''));
            $stream .= "({$safe}) Tj\nT*\n";
        }

        $stream .= "ET";

        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj",
            "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj",
            "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj",
            "5 0 obj << /Length " . strlen($stream) . " >> stream\n{$stream}\nendstream endobj",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer << /Root 1 0 R /Size " . (count($objects) + 1) . " >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}