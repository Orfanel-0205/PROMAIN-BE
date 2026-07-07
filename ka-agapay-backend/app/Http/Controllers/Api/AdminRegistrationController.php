<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\OcrController;
use App\Models\User;
use App\Models\UserRole;
use App\Rules\FilipinoName;
use App\Services\Notification\NotificationService;
use App\Services\PasswordPolicyService;
use Illuminate\Validation\ValidationException;
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
            // Reuse the SAME realistic-name rule as resident registration (Pass 1)
            // so junk like "this-isa-test..." / "POGIII...-123" is rejected here too.
            'first_name' => ['required', 'string', 'max:100', new FilipinoName()],
            'middle_name' => ['nullable', 'string', 'max:100', new FilipinoName()],
            'last_name' => ['required', 'string', 'max:100', new FilipinoName()],
            // Ignore archived (soft-deleted) accounts so a released number/email
            // can be reused — see Part 6 archive-not-delete premise.
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'mobile_number' => ['required', 'regex:/^09\d{9}$/', Rule::unique('users', 'mobile_number')->whereNull('deleted_at')],
            // Barangay is a required dropdown validated against the live list — no
            // free text (BarangayList's own guidance: use Rule::exists, not the const).
            'barangay' => ['required', 'string', Rule::exists('barangays', 'name')],
            'birthday' => ['nullable', 'date', 'before:today'],
            'role' => ['required', Rule::in($this->staffRoles)],
            'password' => ['required', 'confirmed', PasswordPolicyService::standard()],
            'password_confirmation' => ['required'],

            // FINAL RULE: RHU staff/admin must accept the Terms and upload ONE
            // Employee Identification Card photo. Single file only; 5 MB max;
            // JPG/PNG only (a photographed card). Account stays PENDING for review.
            'terms_accepted' => ['required', 'accepted'],
            'employee_id' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'terms_accepted.required' => 'You must accept the Terms and Conditions to register.',
            'terms_accepted.accepted' => 'You must accept the Terms and Conditions to register.',
            'barangay.required' => 'Please choose your barangay from the list.',
            'barangay.exists' => 'Please choose a valid barangay from the list.',
            'employee_id.required' => 'Please upload a clear photo of your Employee ID.',
            'employee_id.file' => 'Please upload a clear photo of your Employee ID.',
            'employee_id.mimes' => 'Please upload a JPG or PNG image of your Employee ID.',
            'employee_id.max' => 'That image is too large. Please upload a photo up to 5 MB.',
        ]);

        $role = UserRole::where('name', $validated['role'])->first();

        if (!$role) {
            return response()->json([
                'message' => "Role '{$validated['role']}' does not exist. Run UserRoleSeeder first.",
            ], 422);
        }

        // PART 5 — hard name-match gate BEFORE any account/file is created. Runs
        // OCR on the TEMPORARY uploaded file (nothing persisted yet) and reuses
        // the resident/PhilHealth scorer at the same 0.65 threshold. A rejected
        // upload therefore leaves NO account, NO ocr_results row, and NO stored
        // file. The extracted fields are reused below so OCR runs only once.
        $ocrFields = $this->verifyEmployeeIdNameMatch($request, $validated);

        $user = DB::transaction(function () use ($request, $validated, $role, $ocrFields) {
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

            if (Schema::hasColumn('users', 'birthday') && !empty($validated['birthday'])) {
                $user->birthday = $validated['birthday'];
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
            $this->storeEmployeeIdDocument($request, $user, (string) $validated['role'], $ocrFields);

            return $user->fresh()->load('role');
        });

        // Part 1 — alert the CORRECT approver that a new staff registration is
        // waiting: the MHO for clinical roles, the Super Admin otherwise. Reuses
        // the existing NotificationService (in-app row) — no new pipeline.
        $this->notifyApprovers($user, (string) $validated['role']);

        return response()->json([
            'message' => 'Registration submitted successfully. Your Employee ID was uploaded. Your account will remain pending until reviewed by the assigned approver.',
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
    private function storeEmployeeIdDocument(Request $request, User $user, string $role, array $fields = []): void
    {
        if (!$request->hasFile('employee_id') || !Schema::hasTable('ocr_results')) {
            return;
        }

        $file = $request->file('employee_id');

        $path = $file->store(
            'ocr/employee-id/' . $user->user_id,
            'public'
        );

        // Reuse the fields already extracted by the pre-create name-match gate so
        // OCR runs only ONCE per registration. Safe nulls if extraction was empty.
        $text = (string) ($fields['text'] ?? '');
        $extractedText = $text !== '' ? $text : null;
        $extractedName = $this->parseEmployeeName($text) ?? ($fields['extracted_name'] ?? null);
        $extractedBirthdate = $fields['extracted_birthdate'] ?? null;
        $extractedIdNumber = $fields['extracted_id_number'] ?? null;
        $confidence = (float) ($fields['confidence'] ?? 0);
        $rawProviderResponse = $fields['raw'] ?? [];

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

    /**
     * PART 5 — run OCR on the TEMP uploaded Employee ID and reject registration
     * if the typed name does not reasonably match the ID, reusing the SAME
     * OcrController::nameMatchScore() + 0.65 threshold as the resident/PhilHealth
     * flows. Returns the extracted fields so the caller persists them without a
     * second OCR call. Nothing is stored here — a rejected upload leaves no
     * account, no ocr_results row, and no stored file.
     */
    private function verifyEmployeeIdNameMatch(Request $request, array $validated): array
    {
        $file = $request->file('employee_id');

        try {
            $fields = app(OcrController::class)->extractDocumentFields(
                $file->getRealPath(),
                (string) ($file->getMimeType() ?: 'image/jpeg')
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[EmployeeIdOCR] pre-create extraction failed.', [
                'error' => $e->getMessage(),
            ]);
            $fields = [];
        }

        $text = (string) ($fields['text'] ?? '');

        if (trim($text) === '') {
            throw ValidationException::withMessages([
                'employee_id' => ['We could not read your Employee ID. Please upload a clearer, well-lit photo.'],
            ]);
        }

        $extractedName = $this->parseEmployeeName($text) ?? ($fields['extracted_name'] ?? null);
        $typedName = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));

        $score = app(OcrController::class)->nameMatchScore($typedName, $extractedName, $text);

        if ($score < 0.65) {
            throw ValidationException::withMessages([
                'employee_id' => ['The name on your Employee ID does not match the name you entered. Please check your name or upload the correct ID.'],
            ]);
        }

        $fields['name_match_score'] = round($score, 2);

        return $fields;
    }

    /**
     * PART 5 — STATELESS Employee-ID OCR autofill. Extracts fields from the TEMP
     * uploaded file and returns them for the form to pre-fill; persists NOTHING
     * (no DB row, no stored file), so re-uploads / abandoned attempts never leave
     * orphans. Advisory only — the real gate is verifyEmployeeIdNameMatch at
     * submission.
     *
     * POST /api/v1/admin/register/extract-employee-id
     */
    public function extractEmployeeId(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'employee_id.required' => 'Please upload a clear photo of your Employee ID.',
            'employee_id.file' => 'Please upload a clear photo of your Employee ID.',
            'employee_id.mimes' => 'Please upload a JPG or PNG image of your Employee ID.',
            'employee_id.max' => 'That image is too large. Please upload a photo up to 5 MB.',
        ]);

        $file = $request->file('employee_id');

        try {
            $fields = app(OcrController::class)->extractDocumentFields(
                $file->getRealPath(),
                (string) ($file->getMimeType() ?: 'image/jpeg')
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'We could not read that image. Please review the details and fill them in.',
                'data' => ['ok' => false, 'fields' => $this->emptyEmployeeFields()],
            ]);
        }

        $text = (string) ($fields['text'] ?? '');

        return response()->json([
            'message' => trim($text) !== ''
                ? 'Employee ID scanned. Please review the details below.'
                : 'We could not read much from that image. Please review and fill in the details.',
            'data' => [
                'ok' => trim($text) !== '',
                'fields' => $this->parseEmployeeIdFields($text, $fields),
            ],
        ]);
    }

    private function emptyEmployeeFields(): array
    {
        return [
            'first_name' => null,
            'middle_name' => null,
            'last_name' => null,
            'full_name' => null,
            'position' => null,
            'office' => null,
            'address' => null,
            'birthday' => null,
        ];
    }

    /**
     * Best-effort parse of the Malasiqui LGU Employee ID layout into form fields.
     * Advisory — the user reviews/edits before submitting. Never throws.
     */
    private function parseEmployeeIdFields(string $text, array $ocr = []): array
    {
        $fullName = $this->parseEmployeeName($text) ?? ($ocr['extracted_name'] ?? null);
        [$first, $middle, $last] = $this->splitEmployeeName($fullName);

        return [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'full_name' => $fullName,
            'position' => $this->parseLabelledValue($text, ['POSITION', 'DESIGNATION']),
            'office' => $this->parseLabelledValue($text, ['OFFICE', 'DEPARTMENT', 'ASSIGNED', 'RURAL HEALTH UNIT', 'RHU']),
            'address' => $this->parseLabelledValue($text, ['ADDRESS']),
            'birthday' => $this->normalizeDate(
                $ocr['extracted_birthdate']
                    ?? $this->parseLabelledValue($text, ['DATE OF BIRTH', 'BIRTH DATE', 'BIRTHDATE', 'BIRTHDAY', 'DOB'])
            ),
        ];
    }

    /** Return the value printed after a label (same line, or the next line). */
    private function parseLabelledValue(string $text, array $labels): ?string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $count = count($lines);

        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            $upper = mb_strtoupper($line);

            foreach ($labels as $label) {
                if (mb_strpos($upper, $label) === false) {
                    continue;
                }

                // Value after "LABEL:" / "LABEL -" on the same line.
                $after = trim((string) preg_replace('/^.*?' . preg_quote($label, '/') . '\s*[:\-]?\s*/iu', '', $line));
                if ($after !== '' && mb_strtoupper($after) !== $label) {
                    return $this->cleanName($after);
                }

                // Otherwise take the next non-empty line.
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = trim($lines[$j]);
                    if ($next !== '') {
                        return $this->cleanName($next);
                    }
                }
            }
        }

        return null;
    }

    /** Split "JUAN SANTOS DELA CRUZ" into [first, middle, last] (best effort). */
    private function splitEmployeeName(?string $name): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim((string) $name)) ?: []));
        $count = count($parts);

        if ($count === 0) {
            return [null, null, null];
        }
        if ($count === 1) {
            return [$this->cleanName($parts[0]), null, null];
        }
        if ($count === 2) {
            return [$this->cleanName($parts[0]), null, $this->cleanName($parts[1])];
        }

        $first = $this->cleanName($parts[0]);
        $last = $this->cleanName($parts[$count - 1]);
        $middle = $this->cleanName(implode(' ', array_slice($parts, 1, $count - 2)));

        return [$first, $middle !== '' ? $middle : null, $last];
    }

    private function normalizeDate(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        if ($string === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($string)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Notify the role-appropriate approver(s) that a new staff registration is
     * awaiting review (Part 1). Clinical roles -> MHO (+ Super Admin as safety
     * net / override); administrative roles -> Super Admin. Calls the existing
     * NotificationService only; never blocks registration.
     */
    private function notifyApprovers(User $applicant, string $role): void
    {
        try {
            $clinical = in_array(
                $this->normalizeRole($role),
                ['doctor', 'nurse', 'midwife', 'bhw'],
                true
            );

            $approverRoles = $clinical
                ? ['mho', 'mho_admin', 'super_admin', 'superadmin']
                : ['super_admin', 'superadmin'];

            $approvers = User::query()
                ->whereHas('role', function ($q) use ($approverRoles) {
                    $q->whereIn(DB::raw('LOWER(name)'), $approverRoles);
                })
                ->when(
                    Schema::hasColumn('users', 'account_status'),
                    fn ($q) => $q->where('account_status', 'active')
                )
                ->get();

            if ($approvers->isEmpty()) {
                return;
            }

            $name = trim(implode(' ', array_filter([
                $applicant->first_name,
                $applicant->last_name,
            ]))) ?: ('User #' . $applicant->user_id);

            $roleLabel = ucwords(str_replace('_', ' ', $this->normalizeRole($role)));
            $awaiting = $clinical ? 'MHO' : 'Super Admin';

            $title = 'New staff registration to review';
            $message = "{$name} registered as {$roleLabel} and is awaiting {$awaiting} approval.";

            $notifier = app(NotificationService::class);

            foreach ($approvers as $approver) {
                $notifier->notifyUser(
                    $approver,
                    'registration_pending_review',
                    $title,
                    $message,
                    [
                        'related_type'    => 'registration',
                        'related_id'      => $applicant->user_id,
                        'applicant_role'  => $this->normalizeRole($role),
                        'awaiting_approver' => $awaiting,
                        'screen'          => 'registrations',
                    ],
                    '/registrations'
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[AdminRegistration] approver notify failed.', [
                'user_id' => $applicant->user_id ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
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