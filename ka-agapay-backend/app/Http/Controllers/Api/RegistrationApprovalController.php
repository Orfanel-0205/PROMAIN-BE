<?php
// app/Http/Controllers/Api/RegistrationApprovalController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Notification\AccountSmsService;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin REGISTRATION APPROVAL workflow.
 *
 * FINAL RULE: every registrant (resident AND staff/admin) stays "pending" until
 * a Super Admin approves them here. Residents submit a valid resident/government
 * ID; RHU staff/admin submit an Employee Identification Card. OCR never
 * auto-approves — only the Super Admin's approval flips account_status to
 * 'active'. Approval is blocked until an ID/Employee-ID document is on file.
 *
 * Routes are already gated to super_admin by the route middleware; this
 * controller adds a defense-in-depth role check and never relies on the
 * frontend to hide actions. super_admin / superadmin accounts are never listed
 * here (they manage the queue and are not themselves approved).
 */
class RegistrationApprovalController extends Controller
{
    private const SUPER_ROLES = ['super_admin', 'superadmin'];

    private const RESIDENT_ROLES = ['resident', 'patient'];

    /** Roles whose required document is an Employee Identification Card. */
    private const STAFF_ROLES = [
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'admin',
        'rhu_admin',
        'mho',
        'mho_admin',
        'municipal_mayor',
        'it_staff',
    ];

    // OCR rows that are NOT identity-verification documents.
    private const NON_ID_OCR_TYPES = ['philhealth', 'prescription'];

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole(self::SUPER_ROLES),
            403,
            'Only a Super Admin can review registrations.'
        );
    }

    /**
     * GET /api/v1/admin/registrations/pending
     * status = pending (default) | rejected | all
     *
     * Lists every registrant awaiting approval — residents and staff/admin —
     * except Super Admin accounts.
     */
    public function pending(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validated = $request->validate([
            'status'   => ['nullable', 'string', 'in:pending,rejected,all'],
            'search'   => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'pending';

        $superRolesSql = "'" . implode("','", self::SUPER_ROLES) . "'";

        $query = User::with('role')
            // Everyone who requires approval = everyone except Super Admin.
            ->whereHas('role', function ($q) use ($superRolesSql) {
                $q->whereRaw(
                    "LOWER(REPLACE(REPLACE(name, ' ', '_'), '-', '_')) NOT IN ({$superRolesSql})"
                );
            })
            ->when($status === 'pending', fn ($q) => $q->where('account_status', 'pending'))
            ->when($status === 'rejected', fn ($q) => $q->where('account_status', 'rejected'))
            ->when($status === 'all', fn ($q) => $q->whereIn('account_status', ['pending', 'rejected']));

        if (!empty($validated['search'])) {
            $search = trim((string) $validated['search']);

            $query->where(function ($inner) use ($search) {
                $inner->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('mobile_number', 'ilike', "%{$search}%")
                    ->orWhere('barangay', 'ilike', "%{$search}%");
            });
        }

        $users = $query->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $users->getCollection()->transform(fn (User $user) => $this->summaryPayload($user));

        return response()->json($users);
    }

    /**
     * GET /api/v1/admin/registrations/{id}/ocr
     * Returns the latest identity-verification OCR record for the registrant
     * (resident ID or staff Employee Identification Card).
     */
    public function ocr(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $user = User::with('role')->findOrFail($id);
        $ocr = $this->latestIdOcr($id);
        $raw = $this->ocrRawMeta($ocr);

        return response()->json([
            'data' => [
                'user' => $this->summaryPayload($user),
                'ocr' => $ocr ? [
                    'id'                  => $ocr->id,
                    'id_type'             => $ocr->id_type ?? null,
                    'document_category'   => $raw['document_category'] ?? $this->documentCategoryForUser($user),
                    'designation'         => $raw['designation'] ?? null,
                    'rhu_label'           => $raw['rhu_label'] ?? null,
                    'municipality'        => $raw['municipality'] ?? null,
                    'status'              => $ocr->status ?? null,
                    'extracted_text'      => $ocr->extracted_text ?? null,
                    'extracted_name'      => $ocr->extracted_name ?? null,
                    'extracted_birthdate' => $ocr->extracted_birthdate ?? null,
                    'extracted_id_number' => $ocr->extracted_id_number ?? null,
                    'name_match_score'    => $ocr->name_match_score ?? null,
                    'date_match_score'    => $ocr->date_match_score ?? null,
                    'overall_match'       => $ocr->overall_match ?? null,
                    'confidence_score'    => $ocr->confidence_score ?? null,
                    'submitted_at'        => $ocr->created_at ?? null,
                    'has_file'            => !empty($ocr->file_path)
                        && Storage::disk('public')->exists($ocr->file_path),
                    'file_url'            => !empty($ocr->file_path)
                        ? url('/api/v1/admin/registrations/' . $id . '/ocr/file')
                        : null,
                ] : null,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/registrations/{id}/ocr/file
     * Streams the uploaded ID / Employee ID image through an AUTHENTICATED,
     * super-admin-only route so the raw storage path is never relied upon for
     * access control.
     */
    public function ocrFile(Request $request, int $id): StreamedResponse
    {
        $this->authorizeSuperAdmin($request);

        $ocr = $this->latestIdOcr($id);

        abort_if(!$ocr || empty($ocr->file_path), 404, 'No ID document on file.');

        $disk = Storage::disk('public');
        abort_unless($disk->exists($ocr->file_path), 404, 'ID document file is missing.');

        // Resolve a correct, explicit Content-Type so the browser can render the
        // image / PDF inline. Prefer the extension mapping, then the disk's own
        // detection, then a safe binary fallback. The raw storage path is never
        // exposed — the streamed filename is a generic "id-document.<ext>".
        $ext = strtolower((string) pathinfo($ocr->file_path, PATHINFO_EXTENSION));

        $mimeByExt = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
            'pdf'  => 'application/pdf',
        ];

        $mime = $mimeByExt[$ext] ?? null;

        if (!$mime) {
            try {
                $mime = $disk->mimeType($ocr->file_path) ?: 'application/octet-stream';
            } catch (\Throwable) {
                $mime = 'application/octet-stream';
            }
        }

        return $disk->response(
            $ocr->file_path,
            'id-document.' . ($ext !== '' ? $ext : 'bin'),
            [
                'Content-Type'  => $mime,
                'Cache-Control' => 'private, max-age=0, no-store',
            ],
            'inline'
        );
    }

    /**
     * POST /api/v1/admin/registrations/{id}/approve
     *
     * Approval is blocked until an ID / Employee ID document exists on file.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $user = User::with('role')->findOrFail($id);

        if (!$this->isApprovable($user)) {
            return response()->json([
                'message' => 'This account is not part of the approval queue.',
            ], 422);
        }

        // FINAL RULE: do not approve without a submitted ID / Employee ID.
        $ocr = $this->latestIdOcr($id);

        if (!$ocr || empty($ocr->file_path) || !Storage::disk('public')->exists($ocr->file_path)) {
            return response()->json([
                'message' => $this->isStaff($user)
                    ? 'Cannot approve yet — no Employee Identification Card has been submitted for review.'
                    : 'Cannot approve yet — no valid ID document has been submitted for review.',
                'requires_document' => true,
            ], 422);
        }

        $reviewerId = (int) ($request->user()->user_id ?? 0);
        $isStaff = $this->isStaff($user);

        DB::transaction(function () use ($user, $reviewerId, $isStaff) {
            $updates = [
                'account_status'   => 'active',
                'id_verified'      => true,
                'rejection_reason' => null,
            ];

            $columns = [
                'approved_by' => $reviewerId,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
            ];

            if ($isStaff) {
                $columns['staff_approved_by'] = $reviewerId;
                $columns['staff_approved_at'] = now();
            }

            foreach ($columns as $column => $value) {
                if (Schema::hasColumn('users', $column)) {
                    $updates[$column] = $value;
                }
            }

            $user->forceFill($updates)->save();
        });

        $this->audit($request, 'REGISTRATION_APPROVED', $user, [
            'document_type' => $ocr->id_type ?? null,
        ]);

        // PART 3b — tell the resident their self-registration was approved so they
        // no longer have to return to the RHU to find out. Logged to sms_logs;
        // never blocks the approval.
        app(AccountSmsService::class)->sendRegistrationApproved($user->fresh());

        return response()->json([
            'message' => 'Registration approved. The user can now sign in.',
            'data' => $this->summaryPayload($user->fresh()->load('role')),
        ]);
    }

    /**
     * POST /api/v1/admin/registrations/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $user = User::with('role')->findOrFail($id);

        if (!$this->isApprovable($user)) {
            return response()->json([
                'message' => 'This account is not part of the approval queue.',
            ], 422);
        }

        $reviewerId = (int) ($request->user()->user_id ?? 0);
        $reason = trim((string) $validated['reason']);

        DB::transaction(function () use ($user, $reviewerId, $reason) {
            $updates = [
                'account_status'   => 'rejected',
                'rejection_reason' => $reason,
            ];

            foreach ([
                'rejected_by' => $reviewerId,
                'rejected_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
            ] as $column => $value) {
                if (Schema::hasColumn('users', $column)) {
                    $updates[$column] = $value;
                }
            }

            $user->forceFill($updates)->save();
        });

        $this->audit($request, 'REGISTRATION_REJECTED', $user, ['reason' => $reason]);

        // PART 3b — tell the resident the outcome + the concrete next step
        // (resubmit a clearer ID). Logged to sms_logs; never blocks the rejection.
        app(AccountSmsService::class)->sendRegistrationRejected($user->fresh(), $reason);

        return response()->json([
            'message' => 'Registration rejected. The user will see the reason on next login.',
            'data' => $this->summaryPayload($user->fresh()->load('role')),
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function latestIdOcr(int $userId): ?object
    {
        if (!Schema::hasTable('ocr_results')) {
            return null;
        }

        return DB::table('ocr_results')
            ->where('user_id', $userId)
            ->whereNotIn('id_type', self::NON_ID_OCR_TYPES)
            ->orderByDesc('id')
            ->first();
    }

    private function ocrRawMeta(?object $ocr): array
    {
        if (!$ocr || empty($ocr->raw_ocr_response)) {
            return [];
        }

        $decoded = json_decode((string) $ocr->raw_ocr_response, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeRole(?string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', (string) $role));
    }

    private function userRole(User $user): string
    {
        return $this->normalizeRole($user->role_name ?? $user->role?->name);
    }

    private function isApprovable(User $user): bool
    {
        return !in_array($this->userRole($user), self::SUPER_ROLES, true);
    }

    private function isStaff(User $user): bool
    {
        return in_array($this->userRole($user), self::STAFF_ROLES, true);
    }

    private function documentCategoryForUser(User $user): string
    {
        return $this->isStaff($user) ? 'employee_id' : 'resident_id';
    }

    private function summaryPayload(User $user): array
    {
        $ocr = $this->latestIdOcr((int) $user->user_id);
        $raw = $this->ocrRawMeta($ocr);
        $rhuId = Rhu::resolveRhuIdFromUser($user);

        $documentType = $ocr->id_type
            ?? ($this->isStaff($user) ? 'Employee Identification Card' : null);

        return [
            'user_id'           => $user->user_id,
            'id'                => $user->user_id,
            'name'              => trim(implode(' ', array_filter([
                (string) $user->first_name,
                (string) ($user->middle_name ?? ''),
                (string) $user->last_name,
            ]))),
            'first_name'        => $user->first_name,
            'middle_name'       => $user->middle_name ?? null,
            'last_name'         => $user->last_name,
            'email'             => $user->email,
            'mobile_number'     => $user->mobile_number,
            'barangay'          => $user->barangay,

            'role'              => $this->userRole($user),
            'is_staff'          => $this->isStaff($user),

            'assigned_rhu_id'   => $rhuId,
            'rhu_label'         => $raw['rhu_label'] ?? Rhu::rhuLabel($rhuId),

            'document_type'     => $documentType,
            'document_category' => $raw['document_category'] ?? $this->documentCategoryForUser($user),
            'designation'       => $raw['designation'] ?? null,

            'account_status'    => $user->account_status,
            'id_verified'       => (bool) $user->id_verified,
            'terms_accepted'    => !empty($user->terms_accepted_at),
            'terms_accepted_at' => optional($user->terms_accepted_at)->toIso8601String(),
            'rejection_reason'  => $user->rejection_reason,
            'submitted_at'      => optional($user->created_at)->toIso8601String(),
            'approved_at'       => optional($user->approved_at ?? $user->staff_approved_at)->toIso8601String(),
            'rejected_at'       => optional($user->rejected_at)->toIso8601String(),
            'ocr_status'        => $ocr->status ?? 'none',
            'ocr_id'            => $ocr->id ?? null,
            'has_id_document'   => $ocr !== null && !empty($ocr->file_path),
        ];
    }

    private function audit(Request $request, string $action, User $user, array $meta): void
    {
        try {
            ActivityLog::create([
                'user_id'       => $request->user()->user_id ?? null,
                'user_role'     => strtolower((string) ($request->user()->role_name ?? 'super_admin')),
                'action'        => $action,
                'module'        => 'registration_approval',
                'severity'      => 'info',
                'subject_type'  => User::class,
                'subject_id'    => $user->user_id,
                'subject_label' => trim((string) $user->first_name . ' ' . (string) $user->last_name),
                'metadata'      => array_merge($meta, [
                    'target_user_id' => $user->user_id,
                    'target_role'    => $this->userRole($user),
                    'ip'             => $request->ip(),
                ]),
                'ip_address'    => $request->ip(),
                'user_agent'    => substr((string) $request->userAgent(), 0, 500),
                'http_method'   => $request->method(),
                'route_name'    => optional($request->route())->getName() ?? $request->path(),
            ]);
        } catch (\Throwable) {
            // Audit logging must never block the approval workflow.
        }
    }
}
