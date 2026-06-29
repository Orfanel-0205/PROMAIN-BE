<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\OcrController;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminRegistrationController extends Controller
{
    private array $staffRoles = [
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'rhu_admin',
    ];

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'mobile_number' => $this->normalizeMobile($request->input('mobile_number')),
            'role' => $this->normalizeRole($request->input('role', 'staff')),
        ]);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'mobile_number' => ['required', 'regex:/^09\d{9}$/', 'unique:users,mobile_number'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'role' => ['required', Rule::in($this->staffRoles)],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required'],

            // FINAL RULE: RHU staff/admin must accept the Terms and upload an
            // Employee Identification Card. The account is created as PENDING and
            // the document is reviewed by the Super Admin before approval.
            'terms_accepted' => ['required', 'accepted'],
            'employee_id' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ], [
            'terms_accepted.required' => 'You must accept the Terms and Conditions to register.',
            'terms_accepted.accepted' => 'You must accept the Terms and Conditions to register.',
            'employee_id.required' => 'Please upload your Employee Identification Card.',
            'employee_id.mimes' => 'Employee ID must be a JPG, PNG, WEBP, or PDF file.',
            'employee_id.max' => 'Employee ID file must be 20MB or smaller.',
        ]);

        $role = UserRole::where('name', $validated['role'])->first();

        if (!$role) {
            return response()->json([
                'message' => "Role '{$validated['role']}' does not exist. Run UserRoleSeeder first.",
            ], 422);
        }

        $user = DB::transaction(function () use ($request, $validated, $role) {
            $user = new User();
            $user->role_id = $role->role_id;
            $user->first_name = trim($validated['first_name']);
            if (Schema::hasColumn('users', 'middle_name')) {
                $user->middle_name = trim((string) ($validated['middle_name'] ?? '')) ?: null;
            }
            $user->last_name = trim($validated['last_name']);
            $user->email = $validated['email'] ?? null;
            $user->mobile_number = $validated['mobile_number'];
            $user->password = Hash::make($validated['password']);
            $user->account_status = 'pending';

            if (Schema::hasColumn('users', 'barangay')) {
                $user->barangay = $validated['barangay'] ?? null;
            }

            if (Schema::hasColumn('users', 'id_verified')) {
                $user->id_verified = false;
            }

            if (Schema::hasColumn('users', 'terms_accepted_at')) {
                $user->terms_accepted_at = now();
            }

            if (Schema::hasColumn('users', 'staff_approved_by')) {
                $user->staff_approved_by = null;
            }

            if (Schema::hasColumn('users', 'staff_approved_at')) {
                $user->staff_approved_at = null;
            }

            if (Schema::hasColumn('users', 'rejection_reason')) {
                $user->rejection_reason = null;
            }

            $user->save();

            // Store the Employee Identification Card and LINK it to the user as
            // an OCR/document record so the Super Admin's "View OCR" + the
            // approve-requires-document gate work. OCR matching is NOT run and
            // this NEVER auto-approves the account.
            $this->storeEmployeeIdDocument($request, $user, (string) $validated['role']);

            return $user->fresh()->load('role');
        });

        return response()->json([
            'message' => 'Registration submitted successfully. Your Employee ID was uploaded. Your account will remain pending until reviewed by the Super Admin.',
            'data' => [
                'user_id' => $user->user_id,
                'name' => trim(implode(' ', array_filter([
                    $user->first_name,
                    $user->middle_name ?? null,
                    $user->last_name,
                ]))),
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name ?? null,
                'last_name' => $user->last_name,
                'mobile_number' => $user->mobile_number,
                'email' => $user->email,
                'role' => $user->role?->name,
                'account_status' => $user->account_status,
                'document_type' => 'Employee Identification Card',
            ],
        ], 201);
    }

    /**
     * Persist the uploaded Employee Identification Card to the public disk and
     * create a linked ocr_results row (id_type = Employee Identification Card).
     *
     * The SAME OCR pipeline used by the working /ocr/upload flow is run here so
     * the Super Admin approval modal shows the extracted text + parsed name.
     *
     * IMPORTANT: OCR extraction NEVER auto-approves the account. The row status
     * stays 'pending' and id_verified / account_status are untouched. If OCR
     * fails for any reason, registration still succeeds and the file preview
     * still works — only the extracted text stays null.
     *
     * Column-filtered so it is safe across schema variations.
     */
    private function storeEmployeeIdDocument(Request $request, User $user, string $role): void
    {
        if (!$request->hasFile('employee_id') || !Schema::hasTable('ocr_results')) {
            return;
        }

        $file = $request->file('employee_id');

        $path = $file->store(
            'ocr/employee-id/' . $user->user_id,
            'public'
        );

        // Attempt OCR extraction using the existing OCR service used by the
        // working School/PhilHealth ID flow. Wrapped so it can never break
        // registration — extraction failure is logged and left as pending.
        $extractedText = null;
        $extractedName = null;
        $extractedBirthdate = null;
        $extractedIdNumber = null;
        $confidence = 0.0;
        $rawProviderResponse = [];

        try {
            $fullPath = Storage::disk('public')->path($path);

            $fields = app(OcrController::class)->extractDocumentFields(
                $fullPath,
                (string) ($file->getMimeType() ?: 'image/jpeg')
            );

            $extractedText = ($fields['text'] ?? '') !== '' ? $fields['text'] : null;
            // Prefer the employee-ID-aware parser; fall back to the generic OCR name.
            $extractedName = $this->parseEmployeeName($fields['text'] ?? '')
                ?? ($fields['extracted_name'] ?? null);
            $extractedBirthdate = $fields['extracted_birthdate'] ?? null;
            $extractedIdNumber = $fields['extracted_id_number'] ?? null;
            $confidence = (float) ($fields['confidence'] ?? 0);
            $rawProviderResponse = $fields['raw'] ?? [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[EmployeeIdOCR] extraction failed; keeping pending.', [
                'user_id' => $user->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        $row = [
            'user_id' => $user->user_id,
            'id_type' => 'Employee Identification Card',
            'file_path' => $path,
            'extracted_text' => $extractedText,
            'extracted_name' => $extractedName,
            'extracted_birthdate' => $extractedBirthdate,
            'extracted_id_number' => $extractedIdNumber,
            'raw_ocr_response' => json_encode([
                'provider' => $rawProviderResponse['provider'] ?? 'ocr.space',
                'document_type' => 'Employee Identification Card',
                'document_category' => 'employee_id',
                'role' => $role,
                'submitted_via' => 'staff_registration',
                'extracted_name' => $extractedName,
                'raw' => $rawProviderResponse,
            ]),
            'confidence_score' => $confidence,
            // Pending for manual Super Admin review — OCR never auto-approves.
            'status' => 'pending',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = Schema::getColumnListing('ocr_results');
        $safe = array_intersect_key($row, array_flip($columns));

        DB::table('ocr_results')->insert($safe);
    }

    /**
     * Best-effort name parser for Employee ID cards.
     *
     * Strategy: scan lines, prefer an ALL-UPPERCASE line of 2–4 words that is
     * not an institutional/header line (RURAL HEALTH UNIT, REPUBLIC, etc.).
     * Returns null when nothing convincing is found — the caller then falls
     * back to the generic OCR name extractor.
     */
    private function parseEmployeeName(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Words that signal a NON-name line (header, agency, address, etc.).
        $stopWords = [
            'REPUBLIC', 'PHILIPPINES', 'IDENTIFICATION', 'CARD', 'ADDRESS',
            'DATE', 'BIRTH', 'RURAL', 'HEALTH', 'UNIT', 'MUNICIPAL', 'MAYOR',
            'AGENCY', 'CORPORATION', 'OFFICE', 'MOBILE', 'PHONE', 'SIGNATURE',
            'VALID', 'LICENSE', 'PHILHEALTH', 'EMPLOYEE', 'POSITION',
            'DESIGNATION', 'GOVERNMENT', 'PROVINCE', 'CITY', 'DEPARTMENT',
            'NAME', 'BLOOD', 'TYPE', 'EMERGENCY', 'CONTACT',
        ];

        $lines = preg_split('/\r?\n+/', $text) ?: [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            // Keep only uppercase letters, spaces, periods, commas, hyphens, Ñ.
            if ($line === '' || !preg_match('/^[A-ZÑ][A-ZÑ .,\'-]+$/u', $line)) {
                continue;
            }

            // Skip institutional / header lines.
            $hasStopWord = false;
            foreach ($stopWords as $stop) {
                if (str_contains($line, $stop)) {
                    $hasStopWord = true;
                    break;
                }
            }
            if ($hasStopWord) {
                continue;
            }

            // Count words; a personal name is typically 2–4 tokens.
            $words = preg_split('/\s+/', trim($line)) ?: [];
            $wordCount = count($words);

            if ($wordCount >= 2 && $wordCount <= 4) {
                return $this->cleanName($line);
            }
        }

        return null;
    }

    private function cleanName(string $value): string
    {
        $value = preg_replace('/\s{2,}/', ' ', trim($value)) ?? $value;
        return trim($value, " .,-");
    }

    private function normalizeMobile(mixed $value): string
    {
        $mobile = preg_replace('/[^\d+]/', '', (string) $value);

        if (str_starts_with($mobile, '+63')) {
            return '0' . substr($mobile, 3);
        }

        if (str_starts_with($mobile, '63') && strlen($mobile) === 12) {
            return '0' . substr($mobile, 2);
        }

        return $mobile;
    }

    private function normalizeRole(mixed $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $value)));
    }
}