<?php

namespace App\Services\Ocr;

use App\Models\User;
use App\Models\VerificationDocument;
use App\Models\OcrResult;
use App\Models\RegistrationApproval;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OcrVerificationService
{
    private string $visionApiKey;

    public function __construct()
    {
        $this->visionApiKey = config('services.google.vision_api_key');
    }

    /**
     * Step 1 — Upload documents during registration.
     */
    public function uploadDocuments(
        User         $user,
        UploadedFile $idPhoto,
        UploadedFile $selfie,
        ?UploadedFile $residencyProof,
        string       $idType
    ): VerificationDocument {
        return DB::transaction(function () use ($user, $idPhoto, $selfie, $residencyProof, $idType) {
            // Store files securely (not publicly accessible)
            $idPath       = $this->storeSecure($idPhoto,       "verification/{$user->user_id}/id");
            $selfiePath   = $this->storeSecure($selfie,         "verification/{$user->user_id}/selfie");
            $residencyPath = $residencyProof
                ? $this->storeSecure($residencyProof, "verification/{$user->user_id}/residency")
                : null;

            $doc = VerificationDocument::create([
                'user_id'       => $user->user_id,
                'id_photo_path' => $idPath,
                'selfie_path'   => $selfiePath,
                'residency_path'=> $residencyPath,
                'id_type'       => $idType,
                'submission_ip' => request()->ip(),
                'submitted_at'  => now(),
            ]);

            // Update user status
            $user->update(['account_status' => 'under_review']);

            // Create approval record
            RegistrationApproval::create([
                'user_id' => $user->user_id,
                'status'  => 'pending',
            ]);

            // Dispatch OCR processing job
            // \App\Jobs\ProcessOcrVerification::dispatch($doc);

            return $doc;
        });
    }

    /**
     * Step 2 — Process OCR via Google Vision API.
     */
    public function processOcr(VerificationDocument $doc): OcrResult
    {
        $ocrResult = OcrResult::create([
            'verification_doc_id' => $doc->id,
            'user_id'             => $doc->user_id,
            'ocr_status'          => 'processing',
        ]);

        try {
            // Extract text from ID photo
            $idText = $this->extractTextFromImage($doc->id_photo_path);

            // Parse extracted text
            $parsed = $this->parseIdText($idText, $doc->id_type);

            // Get user's form data for comparison
            $user = $doc->user;

            // Calculate match scores
            $nameMatch = $this->calculateNameMatch(
                $user->first_name . ' ' . $user->last_name,
                $parsed['name'] ?? ''
            );

            $dateMatch = $this->calculateDateMatch(
                $user->residentProfile?->birth_date,
                $parsed['birthdate'] ?? ''
            );

            $overallMatch = ($nameMatch + $dateMatch) / 2;

            $ocrResult->update([
                'extracted_name'      => $parsed['name'],
                'extracted_birthdate' => $parsed['birthdate'],
                'extracted_address'   => $parsed['address'],
                'extracted_id_number' => $parsed['id_number'],
                'raw_ocr_response'    => ['text' => $idText, 'parsed' => $parsed],
                'confidence_score'    => $parsed['confidence'] ?? 0.0,
                'name_match_score'    => $nameMatch,
                'date_match_score'    => $dateMatch,
                'overall_match'       => $overallMatch,
                'ocr_status'          => $overallMatch >= 0.6 ? 'matched' : 'mismatch',
                'processed_at'        => now(),
            ]);

            // Update approval with OCR result
            RegistrationApproval::where('user_id', $doc->user_id)
                ->update(['ocr_result_id' => $ocrResult->id]);

            // Notify staff that review is needed
            // $this->notifyReviewers($doc->user, $ocrResult);

        } catch (\Throwable $e) {
            $ocrResult->update([
                'ocr_status'    => 'failed',
                'raw_ocr_response' => ['error' => $e->getMessage()],
                'processed_at'  => now(),
            ]);
        }

        return $ocrResult->fresh();
    }

    /**
     * Call Google Vision API.
     */
    private function extractTextFromImage(string $imagePath): string
    {
        $imageContent = base64_encode(
            Storage::disk('local')->get($imagePath)
        );

        $response = Http::post(
            "https://vision.googleapis.com/v1/images:annotate?key={$this->visionApiKey}",
            [
                'requests' => [
                    [
                        'image'    => ['content' => $imageContent],
                        'features' => [
                            ['type' => 'TEXT_DETECTION'],
                            ['type' => 'DOCUMENT_TEXT_DETECTION'],
                        ],
                    ],
                ],
            ]
        );

        if (!$response->successful()) {
            throw new \RuntimeException('Google Vision API error: ' . $response->body());
        }

        return $response->json()['responses'][0]['fullTextAnnotation']['text'] ?? '';
    }

    /**
     * Parse ID text based on ID type.
     */
    private function parseIdText(string $text, string $idType): array
    {
        $lines = array_map('trim', explode("\n", $text));
        $text  = strtoupper($text);

        $result = [
            'name'       => null,
            'birthdate'  => null,
            'address'    => null,
            'id_number'  => null,
            'confidence' => 0.70,
        ];

        // Extract name — look for pattern after "NAME:" or first line after header
        foreach ($lines as $i => $line) {
            if (preg_match('/(?:LAST NAME|SURNAME|APELYIDO)[:\s]+(.+)/i', $line, $m)) {
                $lastName  = trim($m[1]);
                $firstName = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : '';
                $result['name'] = $firstName . ' ' . $lastName;
                break;
            }
            if (preg_match('/^([A-Z]+),\s*([A-Z\s]+)$/i', $line, $m)) {
                $result['name'] = trim($m[2]) . ' ' . trim($m[1]);
                break;
            }
        }

        // Extract birthdate
        if (preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4}|\d{4}[\/\-]\d{2}[\/\-]\d{2})/', $text, $m)) {
            $result['birthdate'] = $m[1];
        }

        // Extract ID number
        if (preg_match('/(?:NO\.|NUMBER|NO)[:\s]+([A-Z0-9\-]+)/i', $text, $m)) {
            $result['id_number'] = trim($m[1]);
        }

        // Extract address
        foreach ($lines as $line) {
            if (preg_match('/(?:ADDRESS|TIRAHAN)[:\s]+(.+)/i', $line, $m)) {
                $result['address'] = trim($m[1]);
                break;
            }
        }

        return $result;
    }

    private function calculateNameMatch(string $formName, string $ocrName): float
    {
        if (empty($ocrName)) return 0.0;
        $formName = strtolower(trim($formName));
        $ocrName  = strtolower(trim($ocrName));
        similar_text($formName, $ocrName, $pct);
        return round($pct / 100, 4);
    }

    private function calculateDateMatch(?string $formDate, string $ocrDate): float
    {
        if (!$formDate || empty($ocrDate)) return 0.0;
        $form = str_replace(['-', '/'], '', $formDate);
        $ocr  = str_replace(['-', '/'], '', $ocrDate);
        return $form === $ocr ? 1.0 : 0.0;
    }

    private function storeSecure(UploadedFile $file, string $path): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        Storage::disk('local')->put("{$path}/{$filename}", file_get_contents($file));
        return "{$path}/{$filename}";
    }

    private function notifyReviewers(User $user, OcrResult $result): void
    {
        // Notification logic would go here
    }
}