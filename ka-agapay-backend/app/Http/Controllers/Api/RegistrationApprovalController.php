<?php
// app/Http/Controllers/Api/RegistrationApprovalController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin resident registration approval workflow.
 *
 * Routes are already gated to super_admin by the route middleware; this
 * controller adds a defense-in-depth role check and never relies on the
 * frontend to hide actions. Residents stay "pending" until a Super Admin
 * approves them here.
 */
class RegistrationApprovalController extends Controller
{
    private const SUPER_ROLES = ['super_admin', 'superadmin'];

    private const RESIDENT_ROLES = ['resident', 'patient'];

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

        $query = User::with('role')
            ->whereHas('role', function ($q) {
                $q->whereRaw(
                    "LOWER(REPLACE(REPLACE(name, ' ', '_'), '-', '_')) IN ('resident', 'patient')"
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
     * Returns the latest identity-verification OCR record for the resident.
     */
    public function ocr(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $user = User::with('role')->findOrFail($id);
        $ocr = $this->latestIdOcr($id);

        return response()->json([
            'data' => [
                'user' => $this->summaryPayload($user),
                'ocr' => $ocr ? [
                    'id'                  => $ocr->id,
                    'id_type'             => $ocr->id_type ?? null,
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
     * Streams the uploaded ID image through an AUTHENTICATED, super-admin-only
     * route so the raw storage path is never relied upon for access control.
     */
    public function ocrFile(Request $request, int $id): StreamedResponse
    {
        $this->authorizeSuperAdmin($request);

        $ocr = $this->latestIdOcr($id);

        abort_if(!$ocr || empty($ocr->file_path), 404, 'No ID document on file.');
        abort_unless(Storage::disk('public')->exists($ocr->file_path), 404, 'ID document file is missing.');

        return Storage::disk('public')->response($ocr->file_path);
    }

    /**
     * POST /api/v1/admin/registrations/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $user = User::with('role')->findOrFail($id);

        if (!$this->isResident($user)) {
            return response()->json([
                'message' => 'Only resident registrations can be approved here.',
            ], 422);
        }

        $reviewerId = (int) ($request->user()->user_id ?? 0);

        DB::transaction(function () use ($user, $reviewerId) {
            $updates = [
                'account_status'   => 'active',
                'id_verified'      => true,
                'rejection_reason' => null,
            ];

            foreach ([
                'approved_by'  => $reviewerId,
                'approved_at'  => now(),
                'rejected_by'  => null,
                'rejected_at'  => null,
            ] as $column => $value) {
                if (Schema::hasColumn('users', $column)) {
                    $updates[$column] = $value;
                }
            }

            $user->forceFill($updates)->save();
        });

        $this->audit($request, 'REGISTRATION_APPROVED', $user, []);

        return response()->json([
            'message' => 'Registration approved. The resident can now sign in.',
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

        if (!$this->isResident($user)) {
            return response()->json([
                'message' => 'Only resident registrations can be rejected here.',
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

        return response()->json([
            'message' => 'Registration rejected. The resident will see the reason on next login.',
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

    private function isResident(User $user): bool
    {
        $role = strtolower(str_replace([' ', '-'], '_', (string) ($user->role_name ?? $user->role?->name)));

        return in_array($role, self::RESIDENT_ROLES, true);
    }

    private function summaryPayload(User $user): array
    {
        $ocr = $this->latestIdOcr((int) $user->user_id);

        return [
            'user_id'           => $user->user_id,
            'id'                => $user->user_id,
            'name'              => trim((string) $user->first_name . ' ' . (string) $user->last_name),
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'email'             => $user->email,
            'mobile_number'     => $user->mobile_number,
            'barangay'          => $user->barangay,
            'account_status'    => $user->account_status,
            'id_verified'       => (bool) $user->id_verified,
            'terms_accepted'    => !empty($user->terms_accepted_at),
            'terms_accepted_at' => optional($user->terms_accepted_at)->toIso8601String(),
            'rejection_reason'  => $user->rejection_reason,
            'submitted_at'      => optional($user->created_at)->toIso8601String(),
            'approved_at'       => optional($user->approved_at)->toIso8601String(),
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
