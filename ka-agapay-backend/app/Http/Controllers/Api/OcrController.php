<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistrationApproval;
use App\Models\VerificationDocument;
use App\Services\Ocr\OcrVerificationService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OcrController extends Controller
{
    public function __construct(
        private readonly OcrVerificationService $ocr,
        private readonly AuditService           $audit
    ) {}

    /**
     * POST /api/v1/ocr/upload
     * Resident uploads verification documents after registration.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'id_photo'   => ['required', 'file', 'image', 'max:5120'],
            'selfie'     => ['required', 'file', 'image', 'max:5120'],
            'residency'  => ['nullable', 'file', 'image', 'max:5120'],
            'id_type'    => ['required', 'in:national_id,drivers_license,passport,voters_id,sss,philhealth,postal_id'],
        ]);

        $user = $request->user();

        // Check if already submitted
        if (VerificationDocument::where('user_id', $user->user_id)->exists()) {
            return response()->json([
                'message' => 'Verification documents already submitted.',
            ], 422);
        }

        $doc = $this->ocr->uploadDocuments(
            $user,
            $request->file('id_photo'),
            $request->file('selfie'),
            $request->file('residency'),
            $request->id_type
        );

        $this->audit->info('ocr.documents_uploaded', 'verification', [
            'subject_id'    => $doc->id,
            'subject_label' => "Verification Doc #{$doc->id}",
            'new_values'    => ['id_type' => $request->id_type],
        ]);

        return response()->json([
            'message' => 'Documents submitted. Processing OCR verification...',
            'data'    => [
                'document_id' => $doc->id,
                'status'      => 'processing',
            ],
        ], 201);
    }

    /**
     * GET /api/v1/ocr/{id}
     * Check OCR result status.
     */
    public function result(Request $request, int $id): JsonResponse
    {
        $doc    = VerificationDocument::findOrFail($id);
        $result = $doc->ocrResult;

        return response()->json([
            'data' => [
                'status'         => $result?->ocr_status ?? 'pending',
                'overall_match'  => $result?->overall_match,
                'name_match'     => $result?->name_match_score,
                'date_match'     => $result?->date_match_score,
                'extracted_name' => $result?->extracted_name,
                'approval_status'=> $doc->user->registrationApproval?->status,
                'processed_at'   => $result?->processed_at,
            ],
        ]);
    }
}