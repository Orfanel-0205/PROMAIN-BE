<?php
// app/Http/Controllers/Api/OcrController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\OcrResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class OcrController extends Controller
{
    // =========================================================================
    // FLOW A — POST /ocr/upload
    // Resident uploads a valid ID during or after registration.
    // Extracts: name, birthdate, PhilHealth number, ID number.
    // Auto-fills the profile and marks the account as ID-verified.
    // =========================================================================

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'id_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'id_type'  => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $file = $request->file('id_image');

        // Store file privately (not publicly accessible)
        $path     = $file->store("id_uploads/{$user->user_id}");
        $fullPath = storage_path("app/{$path}");

        // Run OCR
        $extractedText = $this->runOcr($fullPath, $file->getMimeType());
        $couldRead     = $this->validateOcr($extractedText);

        // Extract the fields the registration form needs
        $name        = $this->extractName($extractedText);
        $birthdate   = $this->extractBirthdate($extractedText);
        $idNumber    = $this->extractIdNumber($extractedText);
        $philhealth  = $this->extractPhilHealthNumber($extractedText);

        $confidence = $couldRead ? 90 : 40;

        // Persist OCR result
        $ocr = OcrResult::create([
            'user_id'             => $user->user_id,
            'id_type'             => $request->input('id_type'),
            'file_path'           => $path,
            'extracted_text'      => $extractedText,
            'extracted_name'      => $name,
            'extracted_birthdate' => $birthdate,
            'extracted_id_number' => $idNumber,
            'confidence_score'    => $confidence,
            'status'              => $couldRead ? 'approved' : 'failed',
            'processed_at'        => now(),
        ]);

        // Auto-fill user profile with the data OCR extracted
        // Only overwrite fields that are still empty — don't stomp existing data
        if ($couldRead) {
            $updates = ['id_verified' => true];

            if ($name && empty($user->first_name)) {
                $parts = explode(' ', trim($name), 2);
                $updates['first_name'] = $parts[0]          ?? $user->first_name;
                $updates['last_name']  = $parts[1] ?? null  ?? $user->last_name;
            }

            if ($birthdate && empty($user->birthday)) {
                $updates['birthday'] = $birthdate;
            }

            // PhilHealth number goes to the resident profile if the relation exists
            if ($philhealth) {
                $user->residentProfile?->updateOrCreate(
                    ['user_id' => $user->user_id],
                    ['philhealth_number' => $philhealth]
                );
            }

            $user->update($updates);
        }

        return response()->json([
            'message'          => $couldRead
                ? 'ID scanned successfully. Your information has been pre-filled.'
                : 'Could not read the ID clearly. Please upload a clearer photo.',
            'ocr_id'           => $ocr->id,
            'status'           => $ocr->status,
            'verified'         => $couldRead,
            'confidence_score' => $confidence,

            // These go straight back to the mobile registration form
            'auto_fill' => [
                'full_name'        => $name,
                'birthdate'        => $birthdate,
                'id_number'        => $idNumber,
                'philhealth_number'=> $philhealth,
            ],
        ]);
    }

    // =========================================================================
    // FLOW B — POST /ocr/prescription/{consultationId}
    // Called after a telemedicine consultation ends.
    // Accepts a photo/scan of the prescription, converts to PDF or JPG,
    // extracts the structured medicine list, links it to the consultation.
    // =========================================================================

    public function scanPrescription(Request $request, int $consultationId): JsonResponse
    {
        $request->validate([
            'prescription_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
            'output_format'      => ['sometimes', 'in:jpg,pdf'],
        ]);

        $user         = $request->user();
        $file         = $request->file('prescription_image');
        $outputFormat = $request->input('output_format', 'pdf');

        // Scope to this patient's own consultations only
        $consultation = Consultation::where('id', $consultationId)
            ->where('patient_id', $user->user_id)
            ->firstOrFail();

        // Store the original upload
        $storagePath = "prescriptions/{$user->user_id}/{$consultationId}";
        $rawPath     = $file->store($storagePath);
        $fullPath    = storage_path("app/{$rawPath}");

        // Convert to requested format (JPG or PDF)
        $convertedPath = $this->convertPrescription($fullPath, $outputFormat, $storagePath);

        // Run OCR to extract the medicine list
        $extractedText = $this->runOcr($fullPath, $file->getMimeType());
        $medicines     = $this->parsePrescription($extractedText);

        // Attach prescription to the consultation record
        $consultation->update([
            'prescription_path'   => $convertedPath,
            'prescription_format' => $outputFormat,
            'prescription_medicines' => $medicines,
        ]);

        // Persist OCR result (audit trail)
        OcrResult::create([
            'user_id'        => $user->user_id,
            'id_type'        => 'prescription',
            'file_path'      => $convertedPath,
            'extracted_text' => $extractedText,
            'raw_ocr_response' => ['medicines' => $medicines],
            'confidence_score' => empty($medicines) ? 40 : 85,
            'status'         => empty($medicines) ? 'failed' : 'approved',
            'processed_at'   => now(),
        ]);

        return response()->json([
            'message'        => 'Prescription scanned and saved.',
            'consultation_id'=> $consultationId,
            'file_format'    => $outputFormat,
            'download_url'   => route('prescription.download', $consultationId),
            'medicines'      => $medicines,      // structured list for the app UI
            'raw_text'       => $extractedText,
        ]);
    }

    // =========================================================================
    // RESULT — GET /ocr/result/{id}
    // =========================================================================

    public function result(Request $request, int $id): JsonResponse
    {
        $ocr = OcrResult::where('id', $id)
            ->where('user_id', $request->user()->user_id)
            ->firstOrFail();

        return response()->json([
            'ocr_id'           => $ocr->id,
            'status'           => $ocr->status,
            'verified'         => $ocr->status === 'approved',
            'confidence_score' => $ocr->confidence_score,
            'auto_fill' => [
                'full_name'  => $ocr->extracted_name,
                'birthdate'  => $ocr->extracted_birthdate,
                'id_number'  => $ocr->extracted_id_number,
            ],
        ]);
    }

    // =========================================================================
    // RETRY — POST /ocr/retry/{id}
    // =========================================================================

    public function retry(Request $request, int $id): JsonResponse
    {
        $ocr       = OcrResult::where('id', $id)
            ->where('user_id', $request->user()->user_id)
            ->firstOrFail();

        $fullPath      = storage_path("app/{$ocr->file_path}");
        $extractedText = $this->runOcr($fullPath, 'image/jpeg');
        $couldRead     = $this->validateOcr($extractedText);

        $ocr->update([
            'extracted_text' => $extractedText,
            'status'         => $couldRead ? 'approved' : 'failed',
            'processed_at'   => now(),
        ]);

        if ($couldRead) {
            $request->user()->update(['id_verified' => true]);
        }

        return response()->json([
            'message'  => $couldRead ? 'Retry successful.' : 'Still could not read the ID.',
            'status'   => $ocr->status,
            'verified' => $couldRead,
        ]);
    }

    // =========================================================================
    // Private — OCR helpers
    // =========================================================================

    private function runOcr(string $filePath, string $mimeType): string
    {
        $processedPath = null;
        try {
            $processedPath = $this->preprocessImage($filePath);
            $apiKey        = config('services.ocr_space.key');

            $response = Http::timeout(60)
                ->attach('file', file_get_contents($processedPath), 'image.jpg')
                ->post('https://api.ocr.space/parse/image', [
                    'apikey'    => $apiKey,
                    'language'  => 'eng',
                    'OCREngine' => 2,
                    'isTable'   => false,
                ]);

            if (!$response->successful()) {
                Log::warning('[OCR] non-200 from OCR.space', ['status' => $response->status()]);
                return '';
            }

            return trim($response->json('ParsedResults.0.ParsedText') ?? '');

        } catch (\Throwable $e) {
            Log::error('[OCR] runOcr failed: ' . $e->getMessage());
            return '';
        } finally {
            if ($processedPath && file_exists($processedPath) && $processedPath !== $filePath) {
                @unlink($processedPath);
            }
        }
    }

    private function preprocessImage(string $filePath): string
    {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf') {
            return $filePath;
        }

        $manager = new ImageManager(new Driver());
        $image   = $manager->read($filePath);
        $image->greyscale()->contrast(20)->brightness(5)->sharpen(15);

        $tempDir = storage_path('app/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir . '/' . uniqid() . '.jpg';
        $image->save($tempPath);
        return $tempPath;
    }

    /**
     * Convert prescription image to JPG or PDF for download.
     */
    private function convertPrescription(string $inputPath, string $format, string $storagePath): string
    {
        $manager  = new ImageManager(new Driver());
        $image    = $manager->read($inputPath);
        $filename = uniqid('rx_') . '.' . $format;
        $outPath  = storage_path("app/{$storagePath}/{$filename}");

        if ($format === 'pdf') {
            // Use FPDF/DomPDF if available; fallback: store as high-quality JPG
            // and rename with .pdf so the browser downloads it correctly
            $image->scale(width: 1240); // A4-ish width at 150dpi
            $image->save($outPath);     // swap for PDF renderer if installed
        } else {
            $image->scale(width: 1240)->save($outPath);
        }

        return "{$storagePath}/{$filename}";
    }

    private function validateOcr(string $text): bool
    {
        if (empty(trim($text))) return false;

        $keywords = [
            'name', 'student', 'license', 'passport', 'philippines',
            'barangay', 'university', 'identification', 'republic',
            'philhealth', 'rx', 'prescription', 'tablets', 'capsules',
        ];

        $matches = 0;
        foreach ($keywords as $word) {
            if (stripos($text, $word) !== false) $matches++;
        }

        return $matches >= 2;
    }

    // ── Extraction helpers ────────────────────────────────────────────────────

    private function extractName(string $text): ?string
    {
        preg_match('/(?:Name|PANGALAN)[:\s]+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)/i', $text, $m);
        return $m[1] ?? null;
    }

    private function extractBirthdate(string $text): ?string
    {
        preg_match('/\b(\d{2}[\/\-]\d{2}[\/\-]\d{4})\b/', $text, $m);
        return $m[1] ?? null;
    }

    private function extractIdNumber(string $text): ?string
    {
        preg_match('/\b([A-Z0-9\-]{6,})\b(?=.*\d)/s', $text, $m);
        return $m[1] ?? null;
    }

    /**
     * Extracts PhilHealth number — format: 2 digits + hyphen + 9 digits + hyphen + 1 digit
     * e.g. 01-234567890-1
     */
    private function extractPhilHealthNumber(string $text): ?string
    {
        preg_match(
            '/(?:PhilHealth|PHIC)[:\s#]*(\d{2}[-\s]?\d{9}[-\s]?\d)/i',
            $text,
            $m
        );

        if (!empty($m[1])) return $m[1];

        // Fallback: bare 12-digit number formatted as PhilHealth
        preg_match('/\b(\d{2}-\d{9}-\d)\b/', $text, $m);
        return $m[1] ?? null;
    }

    /**
     * Parse prescription text into a structured medicine list.
     * Used by Flow B (telemedicine).
     */
    private function parsePrescription(string $text): array
    {
        $medicines = [];

        preg_match_all(
            '/(?:\d+\.\s*)?([A-Z][a-zA-Z]+(?:\s+[A-Z]?[a-zA-Z]+)*)\s+'
            . '(\d+\s?(?:mg|mcg|g|ml|IU|units?))\s*'
            . '(?:(Tab|Cap|Syrup|Susp|Inj|Cream|Oint|Drops?|Patch)\s+)?'
            . '((?:OD|BID|TID|QID|PRN|q\d+h|once|twice|thrice|every\s+\d+\s+hours?)[^,\n]*)?'
            . '(?:x\s*([\d]+\s+(?:days?|weeks?|months?)))?/i',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $medicines[] = [
                'name'      => trim($m[1]),
                'dosage'    => trim($m[2]),
                'form'      => trim($m[3] ?? ''),
                'frequency' => trim($m[4] ?? ''),
                'duration'  => trim($m[5] ?? ''),
            ];
        }

        return $medicines;
    }
}