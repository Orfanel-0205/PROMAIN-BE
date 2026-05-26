<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OcrResult;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrController extends Controller
{
    /**
     * POST /api/v1/ocr/upload
     *
     * Upload and OCR any valid ID:
     * - School ID
     * - Passport
     * - Driver's License
     * - PhilHealth
     * - Barangay ID
     * - Government IDs
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'id_image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:10240', // 10MB
            ],

            'id_type' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $user = $request->user();

        $file = $request->file('id_image');

        // ─────────────────────────────────────────────
        // Store publicly so frontend can preview image
        // ─────────────────────────────────────────────

        $path = $file->store(
            "id_uploads/{$user->user_id}",
            'public'
        );

        // Public preview URL
        $publicUrl = Storage::disk('public')->url($path);

        // ─────────────────────────────────────────────
        // OCR Processing
        // ─────────────────────────────────────────────

        $extractedText = $this->runOcr(
            $file->getRealPath(),
            $file->getMimeType()
        );

        // ─────────────────────────────────────────────
        // Better validation for ALL ID types
        // including school IDs
        // ─────────────────────────────────────────────

        $cleaned = preg_replace(
            '/\s+/',
            '',
            $extractedText
        );

        $couldRead = strlen($cleaned) >= 5;

        // ─────────────────────────────────────────────
        // Save OCR result
        // ─────────────────────────────────────────────

        $ocrResult = OcrResult::create([
            'user_id'        => $user->user_id,

            'id_type'        => $request->input(
                'id_type',
                'unknown'
            ),

            'file_path'      => $path,

            'extracted_text' => $extractedText,

            'status'         => $couldRead
                ? 'approved'
                : 'failed',
        ]);

        // ─────────────────────────────────────────────
        // Mark user as verified
        // ─────────────────────────────────────────────

        if ($couldRead) {
            $user->update([
                'id_verified' => true,
            ]);
        }

        // ─────────────────────────────────────────────
        // Response
        // ─────────────────────────────────────────────

        return response()->json([
            'message' => $couldRead
                ? 'ID uploaded successfully.'
                : 'Could not read ID clearly. Please upload a clearer image.',

            'ocr_id'         => $ocrResult->id,

            'status'         => $ocrResult->status,

            'id_verified'    => $couldRead,

            // IMPORTANT:
            // frontend preview support
            'id_image_url'   => $publicUrl,

            // OCR preview/debugging
            'extracted_text' => $extractedText,

        ], $couldRead ? 200 : 422);
    }

    /**
     * GET /api/v1/ocr/{id}
     */
    public function result(int $id): JsonResponse
    {
        $ocr = OcrResult::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return response()->json([
            'ocr_id'         => $ocr->id,

            'status'         => $ocr->status,

            'id_type'        => $ocr->id_type,

            'id_verified'    => $ocr->status === 'approved',

            'extracted_text' => $ocr->extracted_text,

            'id_image_url'   => Storage::disk('public')
                ->url($ocr->file_path),
        ]);
    }

    /**
     * POST /api/v1/ocr/{id}/retry
     */
    public function retry(int $id): JsonResponse
    {
        $ocr = OcrResult::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $fullPath = Storage::disk('public')
            ->path($ocr->file_path);

        $extractedText = $this->runOcr(
            $fullPath,
            'image/jpeg'
        );

        $cleaned = preg_replace(
            '/\s+/',
            '',
            $extractedText
        );

        $couldRead = strlen($cleaned) >= 5;

        $ocr->update([
            'extracted_text' => $extractedText,

            'status'         => $couldRead
                ? 'approved'
                : 'failed',
        ]);

        if ($couldRead) {
            auth()->user()->update([
                'id_verified' => true,
            ]);
        }

        return response()->json([
            'message' => $couldRead
                ? 'Retry successful.'
                : 'Still could not read the ID.',

            'status'      => $ocr->status,

            'id_verified' => $couldRead,

            'extracted_text' => $extractedText,
        ]);
    }

    // ─────────────────────────────────────────────
    // OCR HELPER
    // ─────────────────────────────────────────────

    private function runOcr(
        string $filePath,
        string $mimeType
    ): string {

        try {

            // OCR.Space API Key
            $apiKey = config(
                'services.ocr_space.key',
                env('OCR_SPACE_API_KEY', 'helloworld')
            );

            $response = Http::timeout(30)
                ->attach(
                    'file',
                    file_get_contents($filePath),
                    'id_image.' . $this->extensionFromMime($mimeType)
                )
                ->post(
                    'https://api.ocr.space/parse/image',
                    [
                        'apikey'    => $apiKey,

                        'language'  => 'eng',

                        // Engine 2 handles IDs better
                        'OCREngine' => 2,

                        'isTable'   => false,
                    ]
                );

            $data = $response->json();

            if (
                isset(
                    $data['ParsedResults'][0]['ParsedText']
                )
            ) {
                return trim(
                    $data['ParsedResults'][0]['ParsedText']
                );
            }

            return '';

        } catch (\Throwable $e) {

            Log::warning(
                '[OCR] Failed: ' . $e->getMessage()
            );

            return '';
        }
    }

    // ─────────────────────────────────────────────
    // MIME HELPER
    // ─────────────────────────────────────────────

    private function extensionFromMime(
        string $mime
    ): string {

        return match ($mime) {

            'image/png' => 'png',

            'image/webp' => 'webp',

            'application/pdf' => 'pdf',

            default => 'jpg',
        };
    }
}