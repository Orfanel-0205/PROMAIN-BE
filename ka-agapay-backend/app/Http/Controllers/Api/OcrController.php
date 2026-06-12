<?php
// app/Http/Controllers/Api/OcrController.php

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
     *
     * Mobile ID verification OCR endpoint.
     * Expected request:
     * - id_type: string
     * - id_image: jpg/jpeg/png/webp/pdf
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:100'],
            'id_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $file = $request->file('id_image');

        $path = $file->store(
            'ocr/id-verification/' . ($user->user_id ?? $user->id),
            'public'
        );

        $fullPath = Storage::disk('public')->path($path);

        $ocr = $this->runOcr($fullPath, (string) $file->getMimeType());

        $text = trim($ocr['text']);
        $name = $this->extractName($text);
        $birthdate = $this->extractBirthdate($text);
        $idNumber = $this->extractIdNumber($text);
        $philhealth = $this->extractPhilHealthNumber($text);

        $verified = $this->validateOcr($text, $name, $idNumber, $birthdate);
        $confidence = $ocr['confidence'] ?: ($verified ? 85 : 40);

        $ocrId = null;

        if (Schema::hasTable('ocr_results')) {
            $ocrId = DB::table('ocr_results')->insertGetId($this->onlyOcrColumns([
                'user_id' => $user->user_id ?? $user->id,
                'id_type' => $validated['id_type'],
                'file_path' => $path,
                'extracted_text' => $text,
                'extracted_name' => $name,
                'extracted_birthdate' => $birthdate,
                'extracted_id_number' => $idNumber,
                'raw_ocr_response' => json_encode($ocr['raw']),
                'confidence_score' => $confidence,
                'status' => $verified ? 'approved' : 'failed',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        if ($verified && Schema::hasTable('users')) {
            $updates = [];

            if (Schema::hasColumn('users', 'id_verified')) {
                $updates['id_verified'] = true;
            }

            // Do not overwrite the user's registered name from noisy OCR.
            if (!empty($updates)) {
                DB::table('users')
                    ->where('user_id', $user->user_id ?? $user->id)
                    ->update($updates);
            }
        }

        return response()->json([
            'message' => $verified
                ? 'ID scanned successfully. Your account is now marked as ID verified.'
                : 'Could not read the ID clearly. Please upload a clearer photo with good lighting.',
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

    /**
     * GET /api/v1/ocr/results/{id}
     */
    public function result(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('ocr_results'), 404, 'OCR results table not found.');

        $row = DB::table('ocr_results')->where('id', $id)->first();

        abort_unless($row, 404, 'OCR result not found.');

        return response()->json([
            'data' => $row,
        ]);
    }

    private function runOcr(string $fullPath, string $mimeType): array
    {
        $apiKey = config('services.ocr_space.key') ?: env('OCR_SPACE_API_KEY');

        if ($apiKey) {
            try {
                $response = Http::timeout(60)
                    ->attach('file', fopen($fullPath, 'r'), basename($fullPath))
                    ->post('https://api.ocr.space/parse/image', [
                        'apikey' => $apiKey,
                        'language' => 'eng',
                        'isOverlayRequired' => 'false',
                        'scale' => 'true',
                        'detectOrientation' => 'true',
                        'OCREngine' => '2',
                    ]);

                if ($response->successful()) {
                    $payload = $response->json();

                    $parsed = collect($payload['ParsedResults'] ?? [])
                        ->pluck('ParsedText')
                        ->filter()
                        ->implode("\n");

                    $errorMessage = $payload['ErrorMessage'] ?? null;

                    if (is_array($errorMessage)) {
                        $errorMessage = implode(' ', $errorMessage);
                    }

                    if (trim($parsed) !== '') {
                        return [
                            'text' => trim($parsed),
                            'confidence' => 85,
                            'raw' => $payload,
                        ];
                    }

                    return [
                        'text' => '',
                        'confidence' => 0,
                        'raw' => [
                            'provider' => 'ocr.space',
                            'error' => $errorMessage ?: 'No text detected.',
                            'payload' => $payload,
                        ],
                    ];
                }

                return [
                    'text' => '',
                    'confidence' => 0,
                    'raw' => [
                        'provider' => 'ocr.space',
                        'error' => 'OCR provider returned HTTP ' . $response->status(),
                        'body' => Str::limit($response->body(), 1000),
                    ],
                ];
            } catch (\Throwable $e) {
                return [
                    'text' => '',
                    'confidence' => 0,
                    'raw' => [
                        'provider' => 'ocr.space',
                        'error' => $e->getMessage(),
                    ],
                ];
            }
        }

        return [
            'text' => '',
            'confidence' => 0,
            'raw' => [
                'provider' => 'none',
                'error' => 'OCR_SPACE_API_KEY is not configured.',
                'mime_type' => $mimeType,
            ],
        ];
    }

    private function extractName(string $text): ?string
    {
        $normalized = $this->normalizeText($text);

        $patterns = [
            '/(?:name|pangalan|full name)\s*[:\-]?\s*([A-ZÑ][A-ZÑ ,.\'-]{4,80})/iu',
            '/(?:apelyido|surname|last name)\s*[:\-]?\s*([A-ZÑ][A-ZÑ ,.\'-]{2,50})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $m)) {
                return $this->cleanField($m[1]);
            }
        }

        $lines = collect(preg_split('/\r?\n+/', $text))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => strlen($line) >= 6 && strlen($line) <= 80)
            ->values();

        foreach ($lines as $line) {
            if (
                preg_match('/^[A-ZÑ][A-ZÑ ,.\'-]+$/u', $line) &&
                !preg_match('/REPUBLIC|PHILIPPINES|IDENTIFICATION|CARD|SIGNATURE|ADDRESS|BIRTH|DATE|SEX|VALID/i', $line)
            ) {
                return $this->cleanField($line);
            }
        }

        return null;
    }

    private function extractBirthdate(string $text): ?string
    {
        $patterns = [
            '/(?:birthdate|birth date|date of birth|dob|kapanganakan)\s*[:\-]?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/iu',
            '/(?:birthdate|birth date|date of birth|dob|kapanganakan)\s*[:\-]?\s*([A-Za-z]+\.?\s+\d{1,2},?\s+\d{4})/iu',
            '/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})\b/u',
            '/\b([A-Za-z]+\.?\s+\d{1,2},?\s+\d{4})\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->cleanField($m[1]);
            }
        }

        return null;
    }

    private function extractIdNumber(string $text): ?string
    {
        $patterns = [
            '/(?:ID\s*No\.?|ID\s*Number|No\.|Number|License\s*No\.?|Card\s*No\.?)\s*[:\-]?\s*([A-Z0-9\- ]{5,40})/iu',
            '/\b([A-Z]{1,4}\-?\d{4,}[\d\- ]*)\b/u',
            '/\b(\d{4}\s?\d{4}\s?\d{4,})\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->cleanField($m[1]);
            }
        }

        return null;
    }

    private function extractPhilHealthNumber(string $text): ?string
    {
        if (preg_match('/\b(\d{2}\-?\d{9}\-?\d{1})\b/u', $text, $m)) {
            return $this->cleanField($m[1]);
        }

        return null;
    }

    private function validateOcr(string $text, ?string $name, ?string $idNumber, ?string $birthdate): bool
    {
        $clean = trim($text);

        if (strlen($clean) < 20) {
            return false;
        }

        $hasIdentityField = $name || $idNumber || $birthdate;

        $hasIdKeyword = preg_match(
            '/\b(ID|IDENTIFICATION|CARD|LICENSE|PASSPORT|PHILHEALTH|PHILSYS|NATIONAL|STUDENT|SCHOOL|DRIVER)\b/i',
            $clean
        );

        return (bool) ($hasIdentityField || $hasIdKeyword);
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/[ \t]+/', ' ', $text));
    }

    private function cleanField(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $value)));
    }

    private function onlyOcrColumns(array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => Schema::hasColumn('ocr_results', (string) $key))
            ->all();
    }
}
