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
    private array $staffRoles = [
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'admin',
        'rhu_admin',
        'mho',
        'municipal_mayor',
        'it_staff',
    ];

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:100'],
            'id_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->loadMissing('role');

        $file = $request->file('id_image');

        $path = $file->store(
            'ocr/id-verification/' . ($user->user_id ?? $user->id),
            'public'
        );

        $fullPath = Storage::disk('public')->path($path);

        $ocr = $this->runOcr($fullPath, (string) $file->getMimeType());

        $text = trim($ocr['text'] ?? '');
        $extractedName = $this->extractName($text);
        $birthdate = $this->extractBirthdate($text);
        $idNumber = $this->extractIdNumber($text);
        $philhealth = $this->extractPhilHealthNumber($text);

        $registeredName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $nameScore = $this->nameMatchScore($registeredName, $extractedName, $text);
        $dateScore = $this->dateMatchScore($user->birthday ?? null, $birthdate);
        $overallMatch = $dateScore === null
            ? $nameScore
            : (($nameScore * 0.75) + ($dateScore * 0.25));

        $hasReadableText = $text !== '';
        $verified = $hasReadableText && $overallMatch >= 0.65;

        $confidence = (float) ($ocr['confidence'] ?? 0);
        if ($confidence <= 0) {
            $confidence = $verified ? 85 : 35;
        }

        $ocrId = null;

        if (Schema::hasTable('ocr_results')) {
            $ocrId = DB::table('ocr_results')->insertGetId($this->onlyOcrColumns([
                'user_id' => $user->user_id ?? $user->id,
                'id_type' => $validated['id_type'],
                'file_path' => $path,
                'extracted_text' => $text,
                'extracted_name' => $extractedName,
                'extracted_birthdate' => $birthdate,
                'extracted_id_number' => $idNumber,
                'raw_ocr_response' => json_encode([
                    'provider' => $ocr['raw']['provider'] ?? 'ocr.space',
                    'raw' => $ocr['raw'] ?? [],
                    'registered_name' => $registeredName,
                    'extracted_name' => $extractedName,
                    'name_match_score' => $nameScore,
                    'date_match_score' => $dateScore,
                    'overall_match' => $overallMatch,
                ]),
                'confidence_score' => $confidence,
                'name_match_score' => round($nameScore, 2),
                'date_match_score' => $dateScore === null ? null : round($dateScore, 2),
                'overall_match' => round($overallMatch, 2),
                'status' => $verified ? 'approved' : 'failed',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        if (Schema::hasTable('users')) {
            $updates = [];

            if (Schema::hasColumn('users', 'id_verified')) {
                $updates['id_verified'] = $verified;
            }

            $roleName = $this->normalizeRoleName($user->role_name ?? $user->role?->name ?? 'resident');

            if ($verified && !in_array($roleName, $this->staffRoles, true)) {
                $updates['account_status'] = 'active';
            }

            if ($verified && in_array($roleName, $this->staffRoles, true) && ($user->account_status !== 'active')) {
                $updates['account_status'] = 'pending';
            }

            if (!empty($updates)) {
                DB::table('users')
                    ->where('user_id', $user->user_id ?? $user->id)
                    ->update($updates);
            }
        }

        return response()->json([
            'message' => $verified
                ? 'ID scanned successfully. Name matched the registered user.'
                : 'OCR verification failed. The ID name must match the registered first name and last name.',
            'ocr_id' => $ocrId,
            'status' => $verified ? 'approved' : 'failed',
            'verified' => $verified,
            'confidence_score' => $confidence,
            'registered_name' => $registeredName,
            'extracted_text' => $text,
            'extracted_name' => $extractedName,
            'birthdate' => $birthdate,
            'id_number' => $idNumber,
            'name_match_score' => round($nameScore, 2),
            'date_match_score' => $dateScore === null ? null : round($dateScore, 2),
            'overall_match' => round($overallMatch, 2),
            'next_step' => $verified && in_array($this->normalizeRoleName($user->role_name ?? 'resident'), $this->staffRoles, true)
                ? 'Your ID is verified. Please wait for MHO, Municipal Mayor, or IT Staff approval.'
                : null,
            'auto_fill' => [
                'full_name' => $extractedName,
                'birthdate' => $birthdate,
                'id_number' => $idNumber,
                'philhealth_number' => $philhealth,
            ],
        ]);
    }

    public function result(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('ocr_results'), 404, 'OCR results table not found.');

        $row = DB::table('ocr_results')->where('id', $id)->first();

        abort_unless($row, 404, 'OCR result not found.');

        return response()->json(['data' => $row]);
    }

    private function runOcr(string $fullPath, string $mimeType): array
    {
        $apiKey = config('services.ocr_space.key') ?: env('OCR_SPACE_API_KEY');

        if (!$apiKey) {
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

            if (!$response->successful()) {
                return [
                    'text' => '',
                    'confidence' => 0,
                    'raw' => [
                        'provider' => 'ocr.space',
                        'error' => 'OCR provider returned HTTP ' . $response->status(),
                        'body' => Str::limit($response->body(), 1000),
                    ],
                ];
            }

            $payload = $response->json();

            $parsed = collect($payload['ParsedResults'] ?? [])
                ->pluck('ParsedText')
                ->filter()
                ->implode("\n");

            return [
                'text' => trim($parsed),
                'confidence' => trim($parsed) !== '' ? 85 : 0,
                'raw' => $payload,
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

    private function extractName(string $text): ?string
    {
        $normalized = $this->normalizeText($text);

        $patterns = [
            '/(?:name|pangalan|full name)\s*[:\-]?\s*([A-ZÑ][A-ZÑ ,.\'-]{4,100})/iu',
            '/(?:apelyido|surname|last name)\s*[:\-]?\s*([A-ZÑ][A-ZÑ ,.\'-]{2,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $match)) {
                return $this->cleanField($match[1]);
            }
        }

        $lines = collect(preg_split('/\r?\n+/', $text))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => strlen($line) >= 6 && strlen($line) <= 100)
            ->values();

        foreach ($lines as $line) {
            if (
                preg_match('/^[A-ZÑ][A-ZÑ ,.\'-]+$/u', $line) &&
                !preg_match('/REPUBLIC|PHILIPPINES|IDENTIFICATION|CARD|SIGNATURE|ADDRESS|BIRTH|DATE|SEX|VALID|LICENSE|PHILHEALTH/i', $line)
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
            if (preg_match($pattern, $text, $match)) {
                return $this->cleanField($match[1]);
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
            if (preg_match($pattern, $text, $match)) {
                return $this->cleanField($match[1]);
            }
        }

        return null;
    }

    private function extractPhilHealthNumber(string $text): ?string
    {
        if (preg_match('/\b(\d{2}\-?\d{9}\-?\d{1})\b/u', $text, $match)) {
            return $this->cleanField($match[1]);
        }

        return null;
    }

    private function nameMatchScore(string $registeredName, ?string $extractedName, string $fullText): float
    {
        $registered = $this->normalizeName($registeredName);
        $candidate = $this->normalizeName($extractedName ?: $fullText);

        if ($registered === '' || $candidate === '') {
            return 0.0;
        }

        if ($registered === $candidate) {
            return 1.0;
        }

        if (str_contains($candidate, $registered)) {
            return 0.95;
        }

        $registeredTokens = array_values(array_filter(explode(' ', $registered)));
        $candidateTokens = array_values(array_filter(explode(' ', $candidate)));

        if (empty($registeredTokens) || empty($candidateTokens)) {
            return 0.0;
        }

        $matched = 0;

        foreach ($registeredTokens as $registeredToken) {
            foreach ($candidateTokens as $candidateToken) {
                if (
                    $registeredToken === $candidateToken ||
                    str_contains($candidateToken, $registeredToken) ||
                    str_contains($registeredToken, $candidateToken)
                ) {
                    $matched++;
                    break;
                }
            }
        }

        $tokenScore = $matched / count($registeredTokens);

        $maxLength = max(strlen($registered), strlen($candidate));
        $levenshteinScore = $maxLength > 0
            ? max(0, 1 - (levenshtein($registered, substr($candidate, 0, min(strlen($candidate), 255))) / $maxLength))
            : 0;

        return max($tokenScore, $levenshteinScore);
    }

    private function dateMatchScore($registeredBirthday, ?string $extractedBirthdate): ?float
    {
        if (!$registeredBirthday || !$extractedBirthdate) {
            return null;
        }

        try {
            $registered = date('Y-m-d', strtotime((string) $registeredBirthday));
            $extracted = date('Y-m-d', strtotime($extractedBirthdate));

            return $registered === $extracted ? 1.0 : 0.0;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeName(?string $name): string
    {
        $name = strtoupper((string) $name);
        $name = preg_replace('/[^A-ZÑ\s]/u', ' ', $name) ?? '';
        $name = preg_replace('/\b(JR|SR|II|III|IV|MR|MS|MRS)\b/u', ' ', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';

        return strtolower(trim($name));
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function cleanField(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $value = trim($value, " \t\n\r\0\x0B:-,.");

        return $value !== '' ? $value : null;
    }

    private function normalizeRoleName(string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($role)));
    }

    private function onlyOcrColumns(array $data): array
    {
        if (!Schema::hasTable('ocr_results')) {
            return $data;
        }

        $columns = Schema::getColumnListing('ocr_results');

        return array_intersect_key($data, array_flip($columns));
    }
}