<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    /**
     * Upload ID and process OCR
     */
    public function upload(
        Request $request
    ): JsonResponse {

        $request->validate([

            'id_image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240',
            ],

            'id_type' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        $user = $request->user();

        $file = $request->file('id_image');

        /*
        |--------------------------------------------------------------------------
        | PRIVATE STORAGE
        |--------------------------------------------------------------------------
        */

        $path = $file->store(
            "id_uploads/{$user->user_id}"
        );

        $fullPath = storage_path(
            "app/{$path}"
        );

        /*
        |--------------------------------------------------------------------------
        | OCR PROCESS
        |--------------------------------------------------------------------------
        */

        $extractedText = $this->runOcr(
            $fullPath,
            $file->getMimeType()
        );

        /*
        |--------------------------------------------------------------------------
        | OCR VALIDATION
        |--------------------------------------------------------------------------
        */

        $couldRead = $this->validateOcr(
            $extractedText
        );

        /*
        |--------------------------------------------------------------------------
        | STRUCTURED EXTRACTION
        |--------------------------------------------------------------------------
        */

        $name = $this->extractName(
            $extractedText
        );

        $birthdate = $this->extractBirthdate(
            $extractedText
        );

        $idNumber = $this->extractIdNumber(
            $extractedText
        );

        /*
        |--------------------------------------------------------------------------
        | CONFIDENCE SCORE
        |--------------------------------------------------------------------------
        */

        $confidence = $couldRead
            ? 90
            : 40;

        /*
        |--------------------------------------------------------------------------
        | SAVE OCR RESULT
        |--------------------------------------------------------------------------
        */

        $ocr = OcrResult::create([

            'user_id' => $user->user_id,

            'id_type' => $request->id_type,

            'file_path' => $path,

            'extracted_text' => $extractedText,

            'extracted_name' => $name,

            'extracted_birthdate' => $birthdate,

            'extracted_id_number' => $idNumber,

            'confidence_score' => $confidence,

            'status' => $couldRead
                ? 'approved'
                : 'failed',

            'processed_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | UPDATE USER VERIFICATION
        |--------------------------------------------------------------------------
        */

        if ($couldRead) {

            $user->update([
                'id_verified' => true,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'message' => $couldRead
                ? 'ID verified successfully'
                : 'OCR failed. Please upload clearer image',

            'ocr_id' => $ocr->id,

            'status' => $ocr->status,

            'verified' => $couldRead,

            'confidence_score' => $confidence,

            'extracted_text' => $extractedText,

            'extracted_name' => $name,

            'birthdate' => $birthdate,

            'id_number' => $idNumber,
        ]);
    }

    /**
     * Get OCR Result
     */
    public function result(
        int $id
    ): JsonResponse {

        $ocr = OcrResult::where(
            'id',
            $id
        )
        ->where(
            'user_id',
            auth()->id()
        )
        ->firstOrFail();

        return response()->json([

            'ocr_id' => $ocr->id,

            'status' => $ocr->status,

            'verified' => $ocr->status === 'approved',

            'confidence_score' => $ocr->confidence_score,

            'extracted_text' => $ocr->extracted_text,

            'extracted_name' => $ocr->extracted_name,

            'birthdate' => $ocr->extracted_birthdate,

            'id_number' => $ocr->extracted_id_number,
        ]);
    }

    /**
     * Retry OCR Processing
     */
    public function retry(
        int $id
    ): JsonResponse {

        $ocr = OcrResult::where(
            'id',
            $id
        )
        ->where(
            'user_id',
            auth()->id()
        )
        ->firstOrFail();

        $fullPath = storage_path(
            "app/{$ocr->file_path}"
        );

        $extractedText = $this->runOcr(
            $fullPath,
            'image/jpeg'
        );

        $couldRead = $this->validateOcr(
            $extractedText
        );

        $ocr->update([

            'extracted_text' => $extractedText,

            'status' => $couldRead
                ? 'approved'
                : 'failed',

            'processed_at' => now(),
        ]);

        if ($couldRead) {

            auth()->user()->update([
                'id_verified' => true,
            ]);
        }

        return response()->json([

            'message' => $couldRead
                ? 'Retry successful'
                : 'OCR still failed',

            'status' => $ocr->status,

            'verified' => $couldRead,

            'extracted_text' => $extractedText,
        ]);
    }

    /**
     * OCR ENGINE
     */
    private function runOcr(
        string $filePath,
        string $mimeType
    ): string {

        $processedPath = null;

        try {

            /*
            |--------------------------------------------------------------------------
            | IMAGE PREPROCESSING
            |--------------------------------------------------------------------------
            */

            $processedPath = $this->preprocessImage(
                $filePath
            );

            $apiKey = env(
                'OCR_SPACE_API_KEY'
            );

            /*
            |--------------------------------------------------------------------------
            | OCR REQUEST
            |--------------------------------------------------------------------------
            */

            $response = Http::timeout(60)
                ->attach(
                    'file',
                    file_get_contents(
                        $processedPath
                    ),
                    'image.jpg'
                )
                ->post(
                    'https://api.ocr.space/parse/image',
                    [

                        'apikey' => $apiKey,

                        'language' => 'eng',

                        'OCREngine' => 2,

                        'isTable' => false,
                    ]
                );

            return trim(
                $response['ParsedResults'][0]['ParsedText']
                ?? ''
            );

        } catch (\Throwable $e) {

            Log::error(
                '[OCR ERROR] ' .
                $e->getMessage()
            );

            return '';

        } finally {

            /*
            |--------------------------------------------------------------------------
            | TEMP FILE CLEANUP
            |--------------------------------------------------------------------------
            */

            if (
                $processedPath &&
                file_exists($processedPath) &&
                $processedPath !== $filePath
            ) {
                unlink($processedPath);
            }
        }
    }

    /**
     * IMAGE PREPROCESSING
     */
    private function preprocessImage(
        string $filePath
    ): string {

        $extension = strtolower(
            pathinfo(
                $filePath,
                PATHINFO_EXTENSION
            )
        );

        /*
        |--------------------------------------------------------------------------
        | SKIP PDF PROCESSING
        |--------------------------------------------------------------------------
        */

        if ($extension === 'pdf') {
            return $filePath;
        }

        $manager = new ImageManager(
            new Driver()
        );

        $image = $manager->read(
            $filePath
        );

        /*
        |--------------------------------------------------------------------------
        | OCR ENHANCEMENT
        |--------------------------------------------------------------------------
        */

        $image->greyscale()
              ->contrast(20)
              ->brightness(5)
              ->sharpen(15);

        /*
        |--------------------------------------------------------------------------
        | TEMP DIRECTORY
        |--------------------------------------------------------------------------
        */

        $tempDir = storage_path(
            'app/temp'
        );

        if (!file_exists($tempDir)) {

            mkdir(
                $tempDir,
                0755,
                true
            );
        }

        /*
        |--------------------------------------------------------------------------
        | TEMP FILE
        |--------------------------------------------------------------------------
        */

        $tempPath = $tempDir . '/' .
            uniqid() .
            '.jpg';

        $image->save($tempPath);

        return $tempPath;
    }

    /**
     * OCR VALIDATION
     */
    private function validateOcr(
        string $text
    ): bool {

        $keywords = [

            'name',
            'student',
            'license',
            'passport',
            'philippines',
            'barangay',
            'university',
            'identification',
            'republic',
        ];

        $matches = 0;

        foreach ($keywords as $word) {

            if (
                stripos(
                    $text,
                    $word
                ) !== false
            ) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    /**
     * NAME EXTRACTION
     */
    private function extractName(
        string $text
    ): ?string {

        preg_match(
            '/Name[:\s]+([A-Z][a-z]+\s[A-Z][a-z]+)/',
            $text,
            $m
        );

        return $m[1] ?? null;
    }

    /**
     * BIRTHDATE EXTRACTION
     */
    private function extractBirthdate(
        string $text
    ): ?string {

        preg_match(
            '/\d{2}\/\d{2}\/\d{4}/',
            $text,
            $m
        );

        return $m[0] ?? null;
    }

    /**
     * ID NUMBER EXTRACTION
     */
    private function extractIdNumber(
        string $text
    ): ?string {

        preg_match(
            '/[A-Z0-9\-]{6,}/',
            $text,
            $m
        );

        return $m[0] ?? null;
    }
}