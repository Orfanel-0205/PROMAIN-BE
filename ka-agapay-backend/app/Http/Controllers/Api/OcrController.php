<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OcrController extends Controller
{
    /**
     * POST /api/v1/ocr/upload
     * ID verification OCR. Keeps your mobile flow working.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:100'],
            'id_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ]);

        $user = $request->user();
        $file = $request->file('id_image');
        $path = $file->store('ocr/id-verification/' . ($user->user_id ?? $user->id), 'public');
        $fullPath = Storage::disk('public')->path($path);

        $text = $this->runOcr($fullPath, (string) $file->getMimeType());
        $name = $this->extractName($text);
        $birthdate = $this->extractBirthdate($text);
        $idNumber = $this->extractIdNumber($text);
        $philhealth = $this->extractPhilHealthNumber($text);
        $verified = $this->validateOcr($text);
        $confidence = $verified ? 90 : 40;

        $ocrId = null;
        if (Schema::hasTable('ocr_results')) {
            $ocrId = DB::table('ocr_results')->insertGetId([
                'user_id' => $user->user_id ?? $user->id,
                'id_type' => $validated['id_type'],
                'file_path' => $path,
                'extracted_text' => $text,
                'extracted_name' => $name,
                'extracted_birthdate' => $birthdate,
                'extracted_id_number' => $idNumber,
                'confidence_score' => $confidence,
                'status' => $verified ? 'approved' : 'failed',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($verified && Schema::hasTable('users')) {
            $updates = [];
            if (Schema::hasColumn('users', 'id_verified')) {
                $updates['id_verified'] = true;
            }
            if ($name && Schema::hasColumn('users', 'first_name')) {
                $parts = preg_split('/\s+/', trim($name), 2);
                $updates['first_name'] = $parts[0] ?? null;
                if (Schema::hasColumn('users', 'last_name')) {
                    $updates['last_name'] = $parts[1] ?? ($user->last_name ?? '');
                }
            }
            if (!empty($updates)) {
                DB::table('users')->where('user_id', $user->user_id ?? $user->id)->update($updates);
            }
        }

        return response()->json([
            'message' => $verified
                ? 'ID scanned successfully. Your information has been pre-filled.'
                : 'Could not read the ID clearly. Please upload a clearer photo.',
            'ocr_id' => $ocrId,
            'status' => $verified ? 'approved' : 'failed',
            'verified' => $verified,
            'confidence_score' => $confidence,
            'extracted_text' => $text,
            'extracted_name' => $name,
            'birthdate' => $birthdate,
            'id_number' => $idNumber,
            'auto_fill' => [
                'full_name' => $name,
                'birthdate' => $birthdate,
                'id_number' => $idNumber,
                'philhealth_number' => $philhealth,
            ],
        ]);
    }

    public function result(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('ocr_results'), 404);
        $row = DB::table('ocr_results')->where('id', $id)->first();
        abort_unless($row, 404);
        return response()->json(['data' => $row]);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        return $this->result($id);
    }

    /**
     * POST /api/v1/ocr/prescription/{consultationId}
     * Uploads prescription image/PDF, extracts medicines, generates PDF, and links it to consultation.
     */
    public function scanPrescription(Request $request, int $consultationId): JsonResponse
    {
        $validated = $request->validate([
            'prescription_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
            'diagnosis' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_unless(Schema::hasTable('consultations'), 500, 'Consultations table is missing.');
        abort_unless(Schema::hasTable('prescriptions'), 500, 'Prescriptions table is missing.');

        $user = $request->user();
        $consultation = DB::table('consultations')->where('id', $consultationId)->first();
        abort_unless($consultation, 404, 'Consultation not found.');

        $file = $request->file('prescription_image');
        $folder = 'prescriptions/' . $consultationId;
        $originalPath = $file->store($folder . '/uploads', 'public');
        $fullPath = Storage::disk('public')->path($originalPath);

        $extractedText = $this->runOcr($fullPath, (string) $file->getMimeType());
        $medicines = $this->parsePrescription($extractedText);

        $residentProfileId = $this->residentProfileId((int) $consultation->user_id);
        $prescriptionNo = $this->nextPrescriptionNumber((int) ($user->barangay_id ?? 1));
        $diagnosis = $validated['diagnosis'] ?? ($consultation->diagnosis ?? null);

        $pdfPath = $folder . '/' . $prescriptionNo . '.pdf';
        Storage::disk('public')->put($pdfPath, $this->buildPdf([
            'Prescription No' => $prescriptionNo,
            'Date' => now()->format('Y-m-d'),
            'Patient ID' => (string) $consultation->user_id,
            'Diagnosis' => $diagnosis ?: 'Not specified',
            'Medicines' => $this->medicinesToText($medicines),
            'Doctor / Staff ID' => (string) ($user->user_id ?? $user->id),
            'Notes' => $validated['notes'] ?? '',
        ]));

        $prescriptionId = DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $residentProfileId,
            'prescribed_by' => $user->user_id ?? $user->id,
            'consultation_id' => $consultationId,
            'telemedicine_session_id' => null,
            'prescription_number' => $prescriptionNo,
            'rhu_id' => (int) ($user->barangay_id ?? 1),
            'prescription_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'diagnosis' => $diagnosis,
            'diagnosis_code' => null,
            'medications' => json_encode($medicines),
            'has_controlled_substances' => false,
            'additional_instructions' => $validated['notes'] ?? null,
            'dispensing_notes' => null,
            'status' => 'active',
            'file_path' => $pdfPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $consultationUpdates = [];
        if (Schema::hasColumn('consultations', 'prescription_path')) {
            $consultationUpdates['prescription_path'] = $pdfPath;
        }
        if (Schema::hasColumn('consultations', 'prescription_format')) {
            $consultationUpdates['prescription_format'] = 'pdf';
        }
        if (Schema::hasColumn('consultations', 'prescription_medicines')) {
            $consultationUpdates['prescription_medicines'] = json_encode($medicines);
        }
        if (!empty($consultationUpdates)) {
            DB::table('consultations')->where('id', $consultationId)->update($consultationUpdates + ['updated_at' => now()]);
        }

        if (Schema::hasTable('ocr_results')) {
            DB::table('ocr_results')->insert([
                'user_id' => $user->user_id ?? $user->id,
                'id_type' => 'prescription',
                'file_path' => $originalPath,
                'extracted_text' => $extractedText,
                'raw_ocr_response' => json_encode(['medicines' => $medicines, 'pdf_path' => $pdfPath]),
                'confidence_score' => $extractedText ? 85 : 25,
                'status' => $extractedText ? 'approved' : 'failed',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Prescription scanned and PDF generated.',
            'data' => [
                'prescription_id' => $prescriptionId,
                'prescription_number' => $prescriptionNo,
                'pdf_path' => $pdfPath,
                'pdf_url' => Storage::disk('public')->url($pdfPath),
                'original_path' => $originalPath,
                'extracted_text' => $extractedText,
                'medicines' => $medicines,
            ],
        ], 201);
    }

    private function runOcr(string $fullPath, string $mimeType): string
    {
        if (str_contains($mimeType, 'pdf')) {
            return 'PDF uploaded. OCR text extraction is only available for images unless a PDF OCR engine is configured.';
        }

        $key = config('services.ocr_space.key') ?: env('OCR_SPACE_API_KEY');
        if (!$key) {
            return '';
        }

        try {
            $response = Http::timeout(45)
                ->attach('file', file_get_contents($fullPath), basename($fullPath))
                ->post('https://api.ocr.space/parse/image', [
                    'apikey' => $key,
                    'language' => 'eng',
                    'OCREngine' => 2,
                    'isOverlayRequired' => 'false',
                ]);

            if (!$response->successful()) {
                return '';
            }

            return trim((string) data_get($response->json(), 'ParsedResults.0.ParsedText', ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function validateOcr(string $text): bool
    {
        return strlen(trim($text)) >= 20;
    }

    private function extractName(string $text): ?string
    {
        if (preg_match('/(?:name|pangalan)[:\s]+([A-Z][A-Z\s,.-]{4,})/i', $text, $m)) {
            return trim($m[1]);
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: [])));
        foreach ($lines as $line) {
            if (preg_match('/^[A-Z][A-Z\s,.\'-]{5,}$/', $line) && !preg_match('/REPUBLIC|PHILIPPINES|CARD|LICENSE|IDENTIFICATION/i', $line)) {
                return Str::title($line);
            }
        }
        return null;
    }

    private function extractBirthdate(string $text): ?string
    {
        if (preg_match('/(?:birth|dob|date of birth)[:\s]*([0-9]{1,2}[\/\-.][0-9]{1,2}[\/\-.][0-9]{2,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractIdNumber(string $text): ?string
    {
        if (preg_match('/(?:id|no\.?|number|pin)[:\s#-]*([A-Z0-9-]{5,})/i', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractPhilHealthNumber(string $text): ?string
    {
        if (preg_match('/\b\d{2}-\d{9}-\d\b/', $text, $m)) {
            return $m[0];
        }
        return null;
    }

    private function parsePrescription(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: [])));
        $medicines = [];

        foreach ($lines as $line) {
            if (preg_match('/(tablet|tab|capsule|cap|syrup|mg|ml|ointment|drops|inject)/i', $line)) {
                $medicines[] = [
                    'name' => Str::limit($line, 120, ''),
                    'dosage' => $this->matchOrNull('/\b\d+\s?(mg|ml|mcg|g)\b/i', $line),
                    'frequency' => $this->matchOrNull('/\b(OD|BID|TID|QID|once|twice|daily|every\s+\d+\s+hours?)\b/i', $line),
                    'duration' => $this->matchOrNull('/\b\d+\s?(day|days|week|weeks)\b/i', $line),
                    'instructions' => $line,
                ];
            }
        }

        if (empty($medicines) && trim($text) !== '') {
            $medicines[] = [
                'name' => 'See OCR extracted text',
                'dosage' => null,
                'frequency' => null,
                'duration' => null,
                'instructions' => Str::limit(trim($text), 500, ''),
            ];
        }

        return $medicines;
    }

    private function matchOrNull(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) ? $m[0] : null;
    }

    private function residentProfileId(int $userId): int
    {
        if (!Schema::hasTable('resident_profiles')) {
            abort(500, 'Resident profiles table is missing.');
        }

        $profile = DB::table('resident_profiles')->where('user_id', $userId)->first();
        if ($profile) {
            return (int) $profile->id;
        }

        return (int) DB::table('resident_profiles')->insertGetId([
            'user_id' => $userId,
            'barangay_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nextPrescriptionNumber(int $rhuId): string
    {
        $prefix = 'RHU' . $rhuId . '-RX-' . now()->format('Y');
        $count = DB::table('prescriptions')->where('prescription_number', 'like', $prefix . '%')->count() + 1;
        return $prefix . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function medicinesToText(array $medicines): string
    {
        if (empty($medicines)) {
            return 'No medicine text detected. Please verify manually.';
        }

        return collect($medicines)->map(function ($med, $index) {
            return ($index + 1) . '. ' . ($med['name'] ?? 'Medicine')
                . (!empty($med['dosage']) ? ' - ' . $med['dosage'] : '')
                . (!empty($med['frequency']) ? ' - ' . $med['frequency'] : '')
                . (!empty($med['duration']) ? ' - ' . $med['duration'] : '');
        })->implode("\n");
    }

    /**
     * Tiny dependency-free PDF generator for thesis/progress use.
     * For production, replace with DomPDF or Snappy for prettier layout.
     */
    private function buildPdf(array $fields): string
    {
        $lines = ['Ka-Agapay E-Prescription', ''];
        foreach ($fields as $key => $value) {
            $valueLines = preg_split('/\r?\n/', (string) $value) ?: [''];
            $lines[] = $key . ': ' . array_shift($valueLines);
            foreach ($valueLines as $extra) {
                $lines[] = '    ' . $extra;
            }
            $lines[] = '';
        }

        $stream = "BT\n/F1 12 Tf\n50 780 Td\n14 TL\n";
        foreach ($lines as $line) {
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], Str::limit($line, 100, ''));
            $stream .= "({$safe}) Tj\nT*\n";
        }
        $stream .= "ET";

        $objects = [];
        $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj";
        $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj";
        $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj";
        $objects[] = "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj";
        $objects[] = "5 0 obj << /Length " . strlen($stream) . " >> stream\n{$stream}\nendstream endobj";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
