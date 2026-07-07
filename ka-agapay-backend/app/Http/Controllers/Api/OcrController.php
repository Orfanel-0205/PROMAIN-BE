<?php
// app/Http/Controllers/Api/OcrController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OcrController extends Controller
{
    // OCR.space engines behave differently on hard images (e.g. an ID with a
    // patterned watermark): try the stronger engine first, then fall back.
    private const OCR_PRIMARY_ENGINE = 2;
    private const OCR_FALLBACK_ENGINE = 1;

    // OCR.space's free tier rejects files above ~1 MB. Uploads are allowed up to
    // 5 MB, so oversized photos are downscaled below this before being sent.
    private const OCR_MAX_UPLOAD_BYTES = 1024 * 1024;

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

    // =========================================================================
    // ID VERIFICATION OCR
    // POST /api/v1/ocr/upload
    // =========================================================================

    public function upload(Request $request): JsonResponse
    {
        // ID verification only accepts real photo IDs: JPG/JPEG/PNG up to 5 MB.
        // (PhilHealth + prescription scans keep their own wider rules below.)
        $validated = $request->validate([
            'id_type' => ['required', 'string', 'max:100'],
            'id_image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'id_image.mimes' => 'Only JPG, JPEG, or PNG images are accepted.',
            'id_image.max'   => 'The ID image must not be larger than 5 MB.',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->loadMissing('role');

        $file = $request->file('id_image');

        $path = $file->store(
            'ocr/id-verification/' . $this->authUserId($user),
            'public'
        );

        $fullPath = Storage::disk('public')->path($path);

        $ocr = $this->runOcr($fullPath, (string) $file->getMimeType());

        $text = trim($ocr['text'] ?? '');
        $extractedName = $this->extractName($text);
        $birthdate = $this->extractBirthdate($text);
        $idNumber = $this->extractIdNumber($text);
        $philhealth = $this->extractPhilHealthNumber($text);

        // EMPLOYEE ID SUPPORT (RHU staff/admin/personnel).
        // When the submitted document is an Employee Identification Card, record
        // the document category and pull the position/designation + RHU/LGU
        // context so the Super Admin can verify the staff member at review time.
        $roleForDoc = $this->normalizeRoleName($user->role_name ?? $user->role?->name ?? 'resident');
        $isEmployeeDoc = in_array($roleForDoc, $this->staffRoles, true)
            || $this->looksLikeEmployeeIdType($validated['id_type']);
        $documentCategory = $isEmployeeDoc ? 'employee_id' : 'resident_id';
        $designation = $isEmployeeDoc ? $this->extractDesignation($text) : null;
        $rhuLabel = $isEmployeeDoc ? $this->extractRhuLabel($text) : null;
        $lgu = $isEmployeeDoc ? $this->extractMunicipality($text) : null;

        $registeredName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        $nameScore = $this->nameMatchScore($registeredName, $extractedName, $text);
        $dateScore = $this->dateMatchScore($user->birthday ?? $user->birth_date ?? null, $birthdate);

        $overallMatch = $dateScore === null
            ? $nameScore
            : (($nameScore * 0.75) + ($dateScore * 0.25));

        $hasReadableText = $text !== '';

        // Reject memes / selfies / landscapes / receipts BEFORE trusting the
        // name match. A real ID must pass geometry + required-keyword + duplicate
        // checks; only then does the name-match gate the final decision.
        $documentValidation = app(\App\Services\Ocr\IdDocumentValidator::class)->validate($fullPath, $text, [
            'category'       => $documentCategory,
            'id_type'        => $validated['id_type'],
            'mime'           => (string) $file->getMimeType(),
            'size_kb'        => $file->getSize() / 1024,
            'user_id'        => $this->authUserId($user),
            'ocr_confidence' => (float) ($ocr['confidence'] ?? 0),
        ]);
        $documentValid = $documentValidation['passed'];

        $verified = $documentValid && $hasReadableText && $overallMatch >= 0.65;

        $confidence = (float) ($ocr['confidence'] ?? 0);
        if ($confidence <= 0) {
            $confidence = $verified ? 85 : 35;
        }

        $ocrId = null;

        if (Schema::hasTable('ocr_results')) {
            $ocrId = DB::table('ocr_results')->insertGetId($this->onlyOcrColumns([
                'user_id' => $this->authUserId($user),
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
                    'document_type' => $validated['id_type'],
                    'document_category' => $documentCategory,
                    'designation' => $designation,
                    'rhu_label' => $rhuLabel,
                    'municipality' => $lgu,
                ]),
                'confidence_score' => $confidence,
                'name_match_score' => round($nameScore, 2),
                'date_match_score' => $dateScore === null ? null : round($dateScore, 2),
                'overall_match' => round($overallMatch, 2),
                'image_hash' => $documentValidation['image_hash'],
                'status' => $verified ? 'approved' : 'failed',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        if (Schema::hasTable('users')) {
            $updates = [];

            // Mark that an ID was scanned + passed name-match. This is ONLY an ID
            // verification flag — it does NOT change account access.
            if (Schema::hasColumn('users', 'id_verified')) {
                $updates['id_verified'] = $verified;
            }

            // RESIDENT FLOW: residents are already active after registration, so
            // OCR only updates id_verified. It never sets account_status and never
            // requires Super Admin approval for basic app access.
            //
            // STAFF FLOW: staff/admin OCR is supporting evidence only. It must NOT
            // auto-approve a staff account — they stay "pending" until a Super
            // Admin approves them through the staff approval flow.
            $roleName = $this->normalizeRoleName($user->role_name ?? $user->role?->name ?? 'resident');

            if (
                $verified
                && in_array($roleName, $this->staffRoles, true)
                && ($user->account_status !== 'active')
            ) {
                // Staff verification keeps its existing "pending staff approval"
                // behaviour; staff are approved through the staff approver flow.
                $updates['account_status'] = 'pending';
            }

            if (!empty($updates)) {
                DB::table('users')
                    ->where('user_id', $this->authUserId($user))
                    ->update($updates);
            }
        }

        // Prefer a specific document-rejection reason (wrong image type, not an
        // ID, duplicate, etc.) over the generic name-mismatch message.
        $failureMessage = !$documentValid && !empty($documentValidation['reasons'])
            ? $documentValidation['reasons'][0]
            : 'OCR verification failed. The ID name must match the registered first name and last name.';

        return response()->json([
            'message' => $verified
                ? 'ID scanned successfully. Name matched the registered user.'
                : $failureMessage,
            'ocr_id' => $ocrId,
            'status' => $verified ? 'approved' : 'failed',
            'verified' => $verified,
            'document_valid' => $documentValid,
            'document_checks' => $documentValidation['checks'],
            'rejection_reasons' => $documentValidation['reasons'],
            'document_type' => $validated['id_type'],
            'document_category' => $documentCategory,
            'designation' => $designation,
            'rhu_label' => $rhuLabel,
            'municipality' => $lgu,
            'confidence_score' => $confidence,
            'registered_name' => $registeredName,
            'extracted_text' => $text,
            'extracted_name' => $extractedName,
            'birthdate' => $birthdate,
            'id_number' => $idNumber,
            'name_match_score' => round($nameScore, 2),
            'date_match_score' => $dateScore === null ? null : round($dateScore, 2),
            'overall_match' => round($overallMatch, 2),
            'next_step' => $verified
                ? (in_array($this->normalizeRoleName($user->role_name ?? 'resident'), $this->staffRoles, true)
                    ? 'Your Employee ID was submitted. Your account is pending Super Admin approval.'
                    : 'Your ID was submitted. Your account is pending Super Admin approval.')
                : null,
            'auto_fill' => [
                'full_name' => $extractedName,
                'birthdate' => $birthdate,
                'id_number' => $idNumber,
                'philhealth_number' => $philhealth,
            ],
        ]);
    }

    // =========================================================================
    // PHILHEALTH OCR VERIFICATION
    // POST /api/v1/ocr/philhealth
    //
    // Scans a PhilHealth ID, extracts the PhilHealth number + printed name, and
    // verifies the printed name against the patient's profile name. Only when the
    // name matches is the number saved to the resident profile + marked verified.
    // Does NOT touch the existing /ocr/upload ID-verification flow.
    // =========================================================================

    public function scanPhilHealth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->loadMissing('role');

        $file = $request->file('id_image');
        $path = $file->store('ocr/philhealth/' . $this->authUserId($user), 'public');
        $fullPath = Storage::disk('public')->path($path);

        $ocr = $this->runOcr($fullPath, (string) $file->getMimeType());
        $text = trim((string) ($ocr['text'] ?? ''));

        $philhealth = $this->extractPhilHealthNumber($text);
        $extractedName = $this->extractName($text);

        // Build the registered name from the resident profile (first+middle+last),
        // falling back to the user account.
        $profile = $this->residentProfileRow($user);
        $registeredName = $this->buildRegisteredName($user, $profile);

        $nameScore = $this->nameMatchScore($registeredName, $extractedName, $text);
        $nameMatched = $text !== '' && $nameScore >= 0.65;
        $hasNumber = $philhealth !== null && $philhealth !== '';

        $verified = $nameMatched && $hasNumber;

        // Always log the OCR attempt.
        $ocrId = null;
        if (Schema::hasTable('ocr_results')) {
            $ocrId = DB::table('ocr_results')->insertGetId($this->onlyOcrColumns([
                'user_id' => $this->authUserId($user),
                'id_type' => 'philhealth',
                'file_path' => $path,
                'extracted_text' => $text,
                'extracted_name' => $extractedName,
                'extracted_id_number' => $philhealth,
                'raw_ocr_response' => json_encode([
                    'provider' => $ocr['raw']['provider'] ?? 'ocr.space',
                    'registered_name' => $registeredName,
                    'extracted_name' => $extractedName,
                    'name_match_score' => $nameScore,
                    'philhealth_number' => $philhealth,
                ]),
                'confidence_score' => (float) ($ocr['confidence'] ?? ($text ? 80 : 10)),
                'name_match_score' => round($nameScore, 2),
                'overall_match' => round($nameScore, 2),
                'status' => $verified ? 'approved' : 'failed',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        if (!$hasNumber) {
            return response()->json([
                'verified' => false,
                'message' => 'No PhilHealth number could be read from the image. Please upload a clearer photo of your PhilHealth ID.',
                'ocr_id' => $ocrId,
                'name_match_score' => round($nameScore, 2),
            ], 422);
        }

        if (!$nameMatched) {
            return response()->json([
                'verified' => false,
                'message' => 'The name on the PhilHealth ID does not match your profile name.',
                'ocr_id' => $ocrId,
                'registered_name' => $registeredName,
                'extracted_name' => $extractedName,
                'name_match_score' => round($nameScore, 2),
            ], 422);
        }

        // Verified → persist to the resident profile (number + verification flags).
        if ($profile && Schema::hasTable('resident_profiles')) {
            $profileUpdates = ['updated_at' => now()];

            if (Schema::hasColumn('resident_profiles', 'philhealth_number')) {
                $profileUpdates['philhealth_number'] = $philhealth;
            }
            if (Schema::hasColumn('resident_profiles', 'philhealth_no')) {
                $profileUpdates['philhealth_no'] = $philhealth;
            }
            if (Schema::hasColumn('resident_profiles', 'philhealth_verified_at')) {
                $profileUpdates['philhealth_verified_at'] = now();
            }
            if (Schema::hasColumn('resident_profiles', 'philhealth_ocr_result_id')) {
                $profileUpdates['philhealth_ocr_result_id'] = $ocrId;
            }
            if (Schema::hasColumn('resident_profiles', 'philhealth_name_matched')) {
                $profileUpdates['philhealth_name_matched'] = true;
            }

            DB::table('resident_profiles')->where('id', $profile->id)->update($profileUpdates);
        }

        return response()->json([
            'verified' => true,
            'message' => 'PhilHealth ID verified. The number was saved to your profile.',
            'ocr_id' => $ocrId,
            'philhealth_number' => $philhealth,
            'philhealth_masked' => $this->maskPhilHealth($philhealth),
            'registered_name' => $registeredName,
            'extracted_name' => $extractedName,
            'name_match_score' => round($nameScore, 2),
            'verified_at' => now()->toIso8601String(),
        ]);
    }

    // =========================================================================
    // E-PRESCRIPTION OCR
    // POST /api/v1/ocr/prescription/{consultationId}
    // =========================================================================

    public function scanPrescription(Request $request, int $consultationId): JsonResponse
    {
        // Scanning a prescription image CREATES an e-prescription order, so it is
        // restricted to prescribers — only a Doctor, MHO, or Super Admin. Nurses,
        // midwives, BHWs, and head nurses cannot create prescriptions this way.
        abort_unless(
            $request->user()?->hasAnyRole(['doctor', 'mho', 'mho_admin', 'super_admin', 'superadmin']),
            403,
            'Only a Doctor, MHO, or Super Admin can create prescriptions. Nurses, midwives, BHWs, and head nurses may only release or dispense an existing prescription.'
        );

        abort_unless(Schema::hasTable('consultations'), 404, 'Consultations table not found.');
        abort_unless(Schema::hasTable('prescriptions'), 404, 'Prescriptions table not found.');

        $consultation = DB::table('consultations')->where('id', $consultationId)->first();

        abort_unless($consultation, 404, 'Consultation not found.');

        $file = $this->resolveUploadedPrescriptionFile($request);

        if (!$file) {
            return response()->json([
                'message' => 'Please upload a prescription image or PDF.',
                'errors' => [
                    'prescription_file' => ['The prescription file field is required.'],
                ],
            ], 422);
        }

        if (!$file->isValid()) {
            return response()->json([
                'message' => 'Uploaded prescription file is invalid.',
            ], 422);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            return response()->json([
                'message' => 'Only JPG, JPEG, PNG, WEBP, or PDF files are allowed.',
            ], 422);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $path = $file->store(
            'ocr/prescriptions/consultation-' . $consultationId,
            'public'
        );

        $fullPath = Storage::disk('public')->path($path);

        $ocr = $this->runOcr($fullPath, (string) $file->getMimeType());

        $rawText = trim((string) ($ocr['text'] ?? ''));
        $confidence = (float) ($ocr['confidence'] ?? 0);

        $parsed = $this->parsePrescriptionText($rawText);

        $medicines = $parsed['medicines'];

        if (empty($medicines)) {
            $medicines = [[
                'name' => 'Unparsed medicine from uploaded prescription',
                'dosage' => '',
                'quantity' => 1,
                'frequency' => '',
                'duration' => '',
                'route' => 'Oral',
                'instructions' => $rawText
                    ? Str::limit($rawText, 500)
                    : 'OCR could not clearly detect medicine details. Please review and edit in E-Prescription.',
                'is_controlled' => false,
                'brand_alternatives_allowed' => true,
            ]];
        }

        $residentProfileId = $this->resolveResidentProfileId($consultation);

        if (!$residentProfileId) {
            return response()->json([
                'message' => 'Resident profile could not be resolved from this consultation.',
            ], 422);
        }

        $rhuId = $this->resolveRhuId($consultation, $user);

        $diagnosis = $request->input('diagnosis')
            ?: $parsed['diagnosis']
            ?: ($consultation->diagnosis ?? null)
            ?: ($consultation->assessment ?? null)
            ?: 'For clinical management';

        $additionalInstructions = $request->input('notes')
            ?: $request->input('additional_instructions')
            ?: $parsed['notes']
            ?: 'Generated from uploaded prescription OCR. Please verify medicine details before release.';

        $number = $this->nextPrescriptionNumber($rhuId);

        $hasControlled = collect($medicines)
            ->contains(fn ($m) => (bool) ($m['is_controlled'] ?? false));

        $prescriptionId = DB::table('prescriptions')->insertGetId(
            $this->onlyPrescriptionColumns([
                'resident_profile_id' => $residentProfileId,
                'prescribed_by' => $this->authUserId($user),
                'consultation_id' => $consultationId > 0 ? $consultationId : null,
                'telemedicine_session_id' => $this->resolveTelemedicineSessionId($consultation),
                'prescription_number' => $number,
                'rhu_id' => $rhuId,
                'prescription_date' => now()->toDateString(),
                'valid_until' => now()->addDays(7)->toDateString(),
                'diagnosis' => $diagnosis,
                'diagnosis_code' => $request->input('diagnosis_code'),
                'medications' => json_encode($medicines),
                'has_controlled_substances' => $hasControlled,
                's2_license_number' => null,
                'additional_instructions' => $additionalInstructions,
                'dispensing_notes' => 'Created from OCR prescription scan.',
                'status' => 'active',
                'file_path' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        $ocrId = null;

        if (Schema::hasTable('ocr_results')) {
            $ocrId = DB::table('ocr_results')->insertGetId($this->onlyOcrColumns([
                'user_id' => $this->authUserId($user),
                'id_type' => 'prescription',
                'file_path' => $path,
                'extracted_text' => $rawText,
                'raw_ocr_response' => json_encode([
                    'provider' => $ocr['raw']['provider'] ?? 'ocr.space',
                    'raw' => $ocr['raw'] ?? [],
                    'parsed' => $parsed,
                    'consultation_id' => $consultationId,
                    'prescription_id' => $prescriptionId,
                ]),
                'confidence_score' => $confidence > 0 ? $confidence : ($rawText ? 75 : 10),
                'status' => $rawText ? 'approved' : 'failed',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $pdfEndpoint = url('/api/v1/prescriptions/' . $prescriptionId . '/pdf');

        return response()->json([
            'message' => 'Prescription scanned and e-prescription record created.',
            'ocr_id' => $ocrId,
            'prescription_id' => $prescriptionId,
            'prescription_number' => $number,
            'pdf_url' => $pdfEndpoint,
            'pdf_endpoint' => $pdfEndpoint,
            'release_url' => '/prescriptions',
            'consultation_id' => $consultationId,
            'resident_profile_id' => $residentProfileId,
            'diagnosis' => $diagnosis,
            'medicines' => $medicines,
            'raw_text' => $rawText,
            'confidence_score' => $confidence > 0 ? $confidence : ($rawText ? 75 : 10),
            'next_step' => 'Open E-Prescription module to review and release this prescription.',
        ], 201);
    }

    // =========================================================================
    // OCR RESULT
    // =========================================================================

    public function result(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('ocr_results'), 404, 'OCR results table not found.');

        $row = DB::table('ocr_results')->where('id', $id)->first();

        abort_unless($row, 404, 'OCR result not found.');

        return response()->json(['data' => $row]);
    }

    public function retry(int $id): JsonResponse
    {
        abort_unless(Schema::hasTable('ocr_results'), 404, 'OCR results table not found.');

        $row = DB::table('ocr_results')->where('id', $id)->first();

        abort_unless($row, 404, 'OCR result not found.');

        $path = $row->file_path ?? null;

        if (!$path || !Storage::disk('public')->exists($path)) {
            return response()->json([
                'message' => 'Original OCR file was not found.',
            ], 404);
        }

        $fullPath = Storage::disk('public')->path($path);

        $ocr = $this->runOcr($fullPath, 'image');

        $text = trim((string) ($ocr['text'] ?? ''));

        DB::table('ocr_results')->where('id', $id)->update($this->onlyOcrColumns([
            'extracted_text' => $text,
            'raw_ocr_response' => json_encode([
                'provider' => $ocr['raw']['provider'] ?? 'ocr.space',
                'raw' => $ocr['raw'] ?? [],
                'retried_at' => now()->toIso8601String(),
            ]),
            'confidence_score' => (float) ($ocr['confidence'] ?? 0),
            'status' => $text ? 'approved' : 'failed',
            'processed_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json([
            'message' => 'OCR retry completed.',
            'data' => DB::table('ocr_results')->where('id', $id)->first(),
        ]);
    }

    // =========================================================================
    // REUSABLE EXTRACTION (used by AdminRegistrationController for Employee ID)
    // Runs the SAME OCR pipeline as the working /ocr/upload flow and returns the
    // structured fields. Never throws — callers can rely on safe defaults.
    // =========================================================================

    public function extractDocumentFields(string $fullPath, string $mimeType): array
    {
        try {
            $ocr = $this->runOcr($fullPath, $mimeType);
            $text = trim((string) ($ocr['text'] ?? ''));

            return [
                'text' => $text,
                'extracted_name' => $this->extractName($text),
                'extracted_birthdate' => $this->extractBirthdate($text),
                'extracted_id_number' => $this->extractIdNumber($text),
                'confidence' => (float) ($ocr['confidence'] ?? 0),
                'raw' => $ocr['raw'] ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'text' => '',
                'extracted_name' => null,
                'extracted_birthdate' => null,
                'extracted_id_number' => null,
                'confidence' => 0.0,
                'raw' => ['provider' => 'ocr.space', 'error' => $e->getMessage()],
            ];
        }
    }

    // =========================================================================
    // OCR PROVIDER
    // =========================================================================

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

        // Shrink oversized photos so OCR.space does not silently reject them for
        // exceeding its file-size limit (best-effort, never fatal).
        [$ocrPath, $ocrMime, $cleanup] = $this->prepareImageForOcr($fullPath, $mimeType);

        try {
            // Try the primary engine, then the alternate one if it returns no
            // text — some watermarked / low-contrast IDs only read on one engine.
            $last = ['text' => '', 'confidence' => 0, 'raw' => ['provider' => 'ocr.space']];

            foreach ([self::OCR_PRIMARY_ENGINE, self::OCR_FALLBACK_ENGINE] as $engine) {
                $result = $this->callOcrSpace($ocrPath, $ocrMime, (string) $apiKey, $engine);
                $last = $result;

                if (trim((string) ($result['text'] ?? '')) !== '') {
                    return $result;
                }
            }

            return $last;
        } finally {
            if ($cleanup && is_file($ocrPath)) {
                @unlink($ocrPath);
            }
        }
    }

    /**
     * Single OCR.space call for one engine. Surfaces the provider's own error
     * signals (IsErroredOnProcessing / OCRExitCode / ErrorMessage) instead of
     * silently returning empty text — that silent discard is what made every
     * failure (size limit, engine timeout, unreadable photo) collapse into the
     * same generic "could not read" message.
     */
    private function callOcrSpace(string $fullPath, string $mimeType, string $apiKey, int $engine): array
    {
        try {
            $handle = fopen($fullPath, 'r');

            if (!$handle) {
                return [
                    'text' => '',
                    'confidence' => 0,
                    'raw' => ['provider' => 'ocr.space', 'engine' => $engine, 'error' => 'Could not open uploaded file for OCR.'],
                ];
            }

            $response = Http::timeout(90)
                ->attach('file', $handle, basename($fullPath))
                ->post('https://api.ocr.space/parse/image', [
                    'apikey' => $apiKey,
                    'language' => 'eng',
                    'isOverlayRequired' => 'false',
                    'scale' => 'true',
                    'detectOrientation' => 'true',
                    'OCREngine' => (string) $engine,
                ]);

            if (is_resource($handle)) {
                fclose($handle);
            }

            if (!$response->successful()) {
                return [
                    'text' => '',
                    'confidence' => 0,
                    'raw' => [
                        'provider' => 'ocr.space',
                        'engine' => $engine,
                        'error' => 'OCR provider returned HTTP ' . $response->status(),
                        'body' => Str::limit($response->body(), 1000),
                    ],
                ];
            }

            $payload = $response->json();

            $errored = (bool) ($payload['IsErroredOnProcessing'] ?? false);
            $errorMessage = $payload['ErrorMessage'] ?? ($payload['ErrorDetails'] ?? null);
            if (is_array($errorMessage)) {
                $errorMessage = trim(implode(' ', array_filter($errorMessage)));
            }

            $parsed = collect($payload['ParsedResults'] ?? [])
                ->pluck('ParsedText')
                ->filter()
                ->implode("\n");

            $text = trim($parsed);

            if ($text === '' && ($errored || $errorMessage)) {
                Log::warning('[OCR] ocr.space returned no text.', [
                    'engine' => $engine,
                    'exit_code' => $payload['OCRExitCode'] ?? null,
                    'message' => $errorMessage,
                ]);
            }

            return [
                'text' => $text,
                'confidence' => $text !== '' ? 85 : 0,
                'raw' => array_merge($payload ?: [], [
                    'provider' => 'ocr.space',
                    'engine' => $engine,
                    'errored' => $errored,
                    'error_message' => $errorMessage,
                ]),
            ];
        } catch (\Throwable $e) {
            return [
                'text' => '',
                'confidence' => 0,
                'raw' => ['provider' => 'ocr.space', 'engine' => $engine, 'error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Downscale + recompress an oversized image so it fits under OCR.space's
     * free-tier size limit before upload. Best-effort only: returns the original
     * path untouched when the file is already small enough, when no image driver
     * (GD / Imagick) is installed, or if preprocessing throws — so it can never
     * break OCR, only help it.
     *
     * @return array{0:string,1:string,2:bool} [pathToSend, mimeType, isTempFile]
     */
    private function prepareImageForOcr(string $fullPath, string $mimeType): array
    {
        if (!is_file($fullPath) || filesize($fullPath) <= self::OCR_MAX_UPLOAD_BYTES) {
            return [$fullPath, $mimeType, false];
        }

        // The image exceeds the provider limit but no image driver is available
        // to shrink it — OCR.space will likely reject it for size and return no
        // text. Log loudly so the fix (enable php-gd / php-imagick) is obvious
        // instead of surfacing as a generic "could not read" to the user.
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            Log::warning('[OCR] Oversized image cannot be downscaled: no GD/Imagick extension installed. '
                . 'OCR.space may reject it for exceeding its size limit. Enable php-gd (or php-imagick) to resolve.', [
                'bytes' => filesize($fullPath),
                'limit_bytes' => self::OCR_MAX_UPLOAD_BYTES,
            ]);

            return [$fullPath, $mimeType, false];
        }

        try {
            $manager = extension_loaded('imagick')
                ? \Intervention\Image\ImageManager::imagick()
                : \Intervention\Image\ImageManager::gd();

            $image = $manager->read($fullPath);

            // Cap the long edge — big phone photos shrink a lot with little OCR
            // loss, and smaller pixels help the provider stay under its limit.
            // scaleDown() only ever shrinks and preserves the aspect ratio.
            $image->scaleDown(2000, 2000);

            foreach ([80, 65, 50, 40] as $quality) {
                $binary = (string) $image->toJpeg($quality);

                if (strlen($binary) <= self::OCR_MAX_UPLOAD_BYTES || $quality === 40) {
                    $tmp = sys_get_temp_dir() . '/' . uniqid('ocr_', true) . '.jpg';
                    file_put_contents($tmp, $binary);
                    return [$tmp, 'image/jpeg', true];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[OCR] image preprocessing failed; sending original.', [
                'error' => $e->getMessage(),
            ]);
        }

        return [$fullPath, $mimeType, false];
    }

    // =========================================================================
    // PRESCRIPTION PARSING
    // =========================================================================

    private function parsePrescriptionText(string $text): array
    {
        $normalized = $this->normalizeText($text);

        $diagnosis = $this->extractPrescriptionDiagnosis($normalized);
        $doctorName = $this->extractPrescriptionDoctor($normalized);
        $date = $this->extractPrescriptionDate($normalized);
        $medicines = $this->extractMedicines($normalized);

        $notes = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        return [
            'medicines' => $medicines,
            'doctor_name' => $doctorName,
            'date' => $date,
            'diagnosis' => $diagnosis,
            'notes' => $notes ? Str::limit($notes, 700) : null,
        ];
    }

    private function extractMedicines(string $text): array
    {
        $lines = collect(preg_split('/\r?\n+/', $text) ?: [])
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '')
            ->values();

        if ($lines->isEmpty()) {
            $lines = collect(preg_split('/(?=\d+\.\s*)/', $text) ?: [])
                ->map(fn ($line) => trim($line))
                ->filter(fn ($line) => $line !== '')
                ->values();
        }

        $medicines = [];

        foreach ($lines as $line) {
            if (!$this->looksLikeMedicineLine($line)) {
                continue;
            }

            $medicines[] = $this->parseMedicineLine($line);
        }

        if (!empty($medicines)) {
            return array_values($medicines);
        }

        preg_match_all(
            '/(?:\d+\.\s*)?([A-Z][a-zA-Z0-9\- ]{2,40})\s+(\d+\s?(?:mg|mcg|g|gram|ml|iu|units?))([^,\n]*)/i',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $line = trim($match[0]);

            if ($this->looksLikeMedicineLine($line)) {
                $medicines[] = $this->parseMedicineLine($line);
            }
        }

        return array_values($medicines);
    }

    private function looksLikeMedicineLine(string $line): bool
    {
        $lower = strtolower($line);

        if (preg_match('/\b(mg|mcg|gram|g|ml|iu|tablet|tab|capsule|cap|syrup|drops?|ointment|cream|oral|od|bid|tid|qid|prn|daily|once|twice|thrice|every|q\d+h)\b/i', $line)) {
            return true;
        }

        if (preg_match('/\b(paracetamol|amoxicillin|metformin|losartan|amlodipine|cetirizine|ibuprofen|salbutamol|omeprazole|simvastatin|atorvastatin)\b/i', $line)) {
            return true;
        }

        return str_contains($lower, 'take ') || str_contains($lower, 'sig:');
    }

    private function parseMedicineLine(string $line): array
    {
        $clean = trim(preg_replace('/^\d+\.\s*/', '', $line) ?? $line);

        $dosage = '';
        if (preg_match('/\b(\d+(?:\.\d+)?\s?(?:mg|mcg|g|gram|ml|iu|units?))\b/i', $clean, $match)) {
            $dosage = trim($match[1]);
        }

        $quantity = 1;
        if (preg_match('/\b(?:qty|quantity)\s*[:\-]?\s*(\d+)\b/i', $clean, $match)) {
            $quantity = (int) $match[1];
        }

        $duration = '';
        if (preg_match('/\b(?:x|for)\s*(\d+\s*(?:day|days|week|weeks|month|months))\b/i', $clean, $match)) {
            $duration = trim($match[1]);
        }

        $route = 'Oral';
        if (preg_match('/\b(oral|po|iv|im|subcutaneous|topical|ophthalmic|otic|nasal|inhalation)\b/i', $clean, $match)) {
            $route = ucfirst(strtolower($match[1]));
        }

        $frequency = '';
        if (preg_match('/\b(od|bid|tid|qid|prn|once daily|twice daily|thrice daily|daily|every\s+\d+\s+hours?|q\d+h|once a day|twice a day|3x a day|2x a day)\b/i', $clean, $match)) {
            $frequency = trim($match[1]);
        }

        $name = $clean;

        if ($dosage) {
            $name = trim(Str::before($clean, $dosage));
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9\- ]{2,40})\b/', $clean, $match)) {
            $name = trim($match[1]);
        }

        $name = trim(preg_replace('/\b(tab|tablet|cap|capsule|syrup|suspension)\b/i', '', $name) ?? $name);
        $name = trim($name, " \t\n\r\0\x0B:-,.");

        if ($name === '') {
            $name = 'Medicine from OCR';
        }

        return [
            'name' => Str::title($name),
            'dosage' => $dosage,
            'quantity' => $quantity,
            'frequency' => $frequency,
            'duration' => $duration,
            'route' => $route,
            'instructions' => $clean,
            'is_controlled' => false,
            'brand_alternatives_allowed' => true,
        ];
    }

    private function extractPrescriptionDiagnosis(string $text): ?string
    {
        $patterns = [
            '/(?:diagnosis|dx|assessment)\s*[:\-]\s*([^\n]+)/i',
            '/(?:impression)\s*[:\-]\s*([^\n]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $this->cleanField($match[1]);
            }
        }

        return null;
    }

    private function extractPrescriptionDoctor(string $text): ?string
    {
        $patterns = [
            '/(?:physician|doctor|prescribed by|dr\.?)\s*[:\-]?\s*(Dr\.?\s*[A-Z][A-Za-z .,\-]+)/i',
            '/(Dr\.?\s+[A-Z][A-Za-z]+(?:\s+[A-Z][A-Za-z]+)+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $this->cleanField($match[1]);
            }
        }

        return null;
    }

    private function extractPrescriptionDate(string $text): ?string
    {
        $patterns = [
            '/(?:date)\s*[:\-]?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i',
            '/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/i',
            '/([A-Za-z]+\.?\s+\d{1,2},?\s+\d{4})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $this->cleanField($match[1]);
            }
        }

        return null;
    }

    // =========================================================================
    // ID PARSING
    // =========================================================================

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

    // =========================================================================
    // DATABASE HELPERS
    // =========================================================================

    private function resolveUploadedPrescriptionFile(Request $request): ?UploadedFile
    {
        return $request->file('prescription_file')
            ?: $request->file('file')
            ?: $request->file('image')
            ?: $request->file('id_image')
            ?: $request->file('prescription');
    }

    private function resolveResidentProfileId(object $consultation): ?int
    {
        if (!empty($consultation->resident_profile_id)) {
            return (int) $consultation->resident_profile_id;
        }

        if (!empty($consultation->user_id) && Schema::hasTable('resident_profiles')) {
            $profileId = DB::table('resident_profiles')
                ->where('user_id', $consultation->user_id)
                ->value('id');

            return $profileId ? (int) $profileId : null;
        }

        return null;
    }

    private function resolveTelemedicineSessionId(object $consultation): ?int
    {
        if (!Schema::hasTable('telemedicine_sessions')) {
            return null;
        }

        if (!empty($consultation->telemedicine_session_id)) {
            return (int) $consultation->telemedicine_session_id;
        }

        $sessionId = DB::table('telemedicine_sessions')
            ->where('consultation_id', $consultation->id)
            ->value('id');

        return $sessionId ? (int) $sessionId : null;
    }

    private function resolveRhuId(object $consultation, object $user): int
    {
        if (!empty($consultation->rhu_id)) {
            return (int) $consultation->rhu_id;
        }

        if (!empty($user->rhu_id)) {
            return (int) $user->rhu_id;
        }

        if (!empty($user->barangay_id)) {
            return (int) $user->barangay_id;
        }

        return 1;
    }

    private function nextPrescriptionNumber(int $rhuId): string
    {
        $prefix = 'RHU' . $rhuId . '-RX-' . now()->format('Y');

        $count = DB::table('prescriptions')
            ->where('prescription_number', 'like', $prefix . '%')
            ->count() + 1;

        return $prefix . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function onlyPrescriptionColumns(array $data): array
    {
        if (!Schema::hasTable('prescriptions')) {
            return $data;
        }

        $columns = Schema::getColumnListing('prescriptions');

        return array_intersect_key($data, array_flip($columns));
    }

    private function onlyOcrColumns(array $data): array
    {
        if (!Schema::hasTable('ocr_results')) {
            return $data;
        }

        $columns = Schema::getColumnListing('ocr_results');

        return array_intersect_key($data, array_flip($columns));
    }

    private function authUserId(object $user): int
    {
        return (int) (
            $user->user_id
            ?? $user->getKey()
            ?? 0
        );
    }

    private function residentProfileRow(object $user): ?object
    {
        if (!Schema::hasTable('resident_profiles')) {
            return null;
        }

        return DB::table('resident_profiles')
            ->where('user_id', $this->authUserId($user))
            ->first();
    }

    private function buildRegisteredName(object $user, ?object $profile): string
    {
        $parts = [
            $profile->first_name ?? $user->first_name ?? null,
            $profile->middle_name ?? null,
            $profile->last_name ?? $user->last_name ?? null,
        ];

        $name = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter(array_map('trim', array_map('strval', array_filter($parts, fn ($p) => $p !== null)))))) ?? '');

        return $name !== '' ? $name : trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
    }

    private function maskPhilHealth(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $number) ?? '';
        $last4 = substr($digits, -4);

        if ($last4 === '') {
            return null;
        }

        return '****-****-' . str_pad($last4, 4, '*', STR_PAD_LEFT);
    }

    // =========================================================================
    // MATCHING HELPERS
    // =========================================================================

    // Public so the staff self-registration flow (AdminRegistrationController)
    // can reuse the SAME name-match scoring the resident/PhilHealth ID flows use,
    // instead of duplicating it. Internal logic is unchanged.
    public function nameMatchScore(string $registeredName, ?string $extractedName, string $fullText): float
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

    // =========================================================================
    // TEXT HELPERS
    // =========================================================================

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

    // =========================================================================
    // EMPLOYEE ID HELPERS (RHU staff/admin Employee Identification Card)
    // =========================================================================

    private function looksLikeEmployeeIdType(?string $idType): bool
    {
        $type = strtolower((string) $idType);

        return str_contains($type, 'employee')
            || str_contains($type, 'employment')
            || str_contains($type, 'company id')
            || str_contains($type, 'office id');
    }

    /**
     * Best-effort position/designation from an Employee ID (e.g. "Nurse II",
     * "Midwife", "RHU Admin"). Returns null when nothing recognisable is found.
     */
    private function extractDesignation(string $text): ?string
    {
        $patterns = [
            '/(?:position|designation|title|rank|role)\s*[:\-]\s*([A-Za-z0-9 .,\/&\-]{2,60})/i',
            '/\b(Municipal Health Officer|Rural Health Physician|Medical Officer[^\n]{0,12}|Public Health Nurse|Nurse\s+[IVX]+|Midwife\s*[IVX]*|Rural Health Midwife|Barangay Health Worker|Sanitary Inspector|Medical Technologist|RHU Admin(?:istrator)?|Administrative (?:Aide|Officer)[^\n]{0,8}|Pharmacist|Dentist|Doctor)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $this->cleanField($match[1]);
            }
        }

        return null;
    }

    /**
     * Detect the RHU facility printed on an Employee ID and return a normalised
     * label ("RHU 1" / "RHU 2"). Handles "RHU-2", "RHU II", and the Don Pedro
     * (RHU 2) satellite naming.
     */
    private function extractRhuLabel(string $text): ?string
    {
        if (preg_match('/\bRHU\s*[-#]?\s*0*([12])\b/i', $text, $m)) {
            return 'RHU ' . $m[1];
        }

        if (preg_match('/\bRHU\s*[-#]?\s*(I{1,2})\b/i', $text, $m)) {
            return strtoupper($m[1]) === 'II' ? 'RHU 2' : 'RHU 1';
        }

        if (preg_match('/\bdon\s*pedro\b/i', $text)) {
            return 'RHU 2';
        }

        if (preg_match('/\brural\s+health\s+unit\s*[-#]?\s*0*([12])\b/i', $text, $m)) {
            return 'RHU ' . $m[1];
        }

        return null;
    }

    private function extractMunicipality(string $text): ?string
    {
        if (preg_match('/(?:municipality|lgu|city|town)\s*(?:of)?\s*[:\-]?\s*([A-Za-z .\-]{3,40})/i', $text, $m)) {
            return $this->cleanField($m[1]);
        }

        if (preg_match('/\b(Malasiqui)\b/i', $text, $m)) {
            return $this->cleanField($m[1]);
        }

        return null;
    }
}