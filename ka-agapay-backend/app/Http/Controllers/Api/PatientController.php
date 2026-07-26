<?php
// app/Http/Controllers/Api/PatientController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PatientController extends Controller
{
    // =========================================================================
    // GET /api/v1/patients/search
    //
    // Search patients for prescription creation.
    // Returns resident_profile_id automatically.
    // If the user has no resident profile yet, it creates one so prescriptions
    // can be linked and shown in the patient's mobile Records page.
    // =========================================================================

    public function searchForPrescription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        if (!Schema::hasTable('users')) {
            return response()->json([
                'data' => [],
            ]);
        }

        if (!Schema::hasTable('resident_profiles')) {
            return response()->json([
                'message' => 'resident_profiles table not found.',
                'data' => [],
            ], 404);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 10);

        if ($search === '') {
            return response()->json([
                'data' => [],
            ]);
        }

        $likeOperator = DB::connection()->getDriverName() === 'pgsql'
            ? 'ILIKE'
            : 'LIKE';

        $query = DB::table('users as u')
            ->leftJoin('resident_profiles as rp', 'rp.user_id', '=', 'u.user_id');

        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id');
            $barangaySelect = 'b.name as barangay';
        } else {
            $barangaySelect = 'NULL as barangay';
        }

        $rows = $query
            ->selectRaw('u.user_id')
            ->selectRaw('u.first_name')
            ->selectRaw('u.last_name')
            ->selectRaw('u.mobile_number')
            ->selectRaw('u.email')
            ->selectRaw('rp.id as resident_profile_id')
            ->selectRaw($barangaySelect)
            ->where(function ($q) use ($search, $likeOperator) {
                $q->where('u.first_name', $likeOperator, "%{$search}%")
                    ->orWhere('u.last_name', $likeOperator, "%{$search}%")
                    ->orWhere('u.mobile_number', $likeOperator, "%{$search}%")
                    ->orWhere('u.email', $likeOperator, "%{$search}%")
                    ->orWhereRaw(
                        "CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) {$likeOperator} ?",
                        ["%{$search}%"]
                    );
            })
            ->orderBy('u.first_name')
            ->limit($limit)
            ->get();

        $data = $rows->map(function ($row) {
            $profileId = $row->resident_profile_id;

            if (!$profileId) {
                $profileId = DB::table('resident_profiles')->insertGetId([
                    'user_id' => $row->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $fullName = trim(
                ((string) ($row->first_name ?? '')) . ' ' . ((string) ($row->last_name ?? ''))
            );

            return [
                'user_id' => (int) $row->user_id,
                'resident_profile_id' => (int) $profileId,
                'patient_id' => 'PAT-' . str_pad((string) $row->user_id, 6, '0', STR_PAD_LEFT),
                'full_name' => $fullName !== '' ? $fullName : 'Patient #' . $row->user_id,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'mobile_number' => $row->mobile_number,
                'email' => $row->email,
                'barangay' => $row->barangay,
            ];
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }

    // =========================================================================
    // GET /api/v1/patient/me
    // Resident reads their own profile
    // =========================================================================

    // =========================================================================
    // GET /api/v1/patients/{userId}/profile
    //
    // Per-patient health profile: identity + summary + full completed-consultation
    // history with the SAME ITR/SOAP field set used by the Reports module (it
    // reuses DiagnosisItrReportService, so nothing is redefined here). The service
    // RHU-scopes by the viewer, so a patient's records are only ever visible to
    // staff in their accessible RHU — a patient outside it is not revealed.
    // =========================================================================

    // =========================================================================
    // GET /api/v1/patients/registry
    //
    // Browsable, searchable, paginated roster of active patients — the "front
    // door" that feeds the individual Patient Profile page. Columns mirror the
    // profile's summary (total visits, last visit, most recent diagnosis,
    // follow-ups needed). RHU-scoped exactly like Consultations/Reports via the
    // shared Rhu helper: a facility-locked viewer sees only their RHU's patients.
    // =========================================================================

    public function registry(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $search = trim((string) $request->query('search', ''));
        $perPage = min(100, max(10, (int) $request->query('per_page', 20)));

        // null = global scope (super_admin/mho sees all); else locked to 1/2.
        $effectiveRhu = Rhu::filterRhuId($viewer, null);

        $completed = "LOWER(COALESCE(c.status,'')) = 'completed'";

        $query = DB::table('users as u')
            ->leftJoin('resident_profiles as rp', 'rp.user_id', '=', 'u.user_id')
            ->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
            ->leftJoin('user_roles as ur', 'ur.role_id', '=', 'u.role_id')
            ->where('u.account_status', 'active')
            ->whereRaw("LOWER(COALESCE(ur.name,'')) IN ('resident','patient')");

        // RHU scoping by the patient's barangay facility (RHU 1 also owns
        // legacy/unmapped barangays), matching every other module's discipline.
        if ($effectiveRhu !== null) {
            if ($effectiveRhu === Rhu::DEFAULT_ID) {
                $query->where(function ($w) {
                    $w->where('b.rhu_id', Rhu::DEFAULT_ID)
                        ->orWhereNull('b.rhu_id')
                        ->orWhereNotIn('b.rhu_id', Rhu::IDS);
                });
            } else {
                $query->where('b.rhu_id', $effectiveRhu);
            }
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($w) use ($like) {
                $w->where('u.first_name', 'ILIKE', $like)
                    ->orWhere('u.last_name', 'ILIKE', $like)
                    ->orWhere('u.mobile_number', 'ILIKE', $like)
                    ->orWhereRaw("(u.first_name || ' ' || u.last_name) ILIKE ?", [$like]);
            });
        }

        $query->selectRaw("
            u.user_id,
            u.first_name,
            u.last_name,
            u.mobile_number,
            rp.sex as sex,
            rp.birth_date as birth_date,
            b.name as barangay,
            b.rhu_id as rhu_id,
            (SELECT COUNT(*) FROM consultations c WHERE c.user_id = u.user_id AND {$completed}) as total_visits,
            (SELECT MAX(COALESCE(c.consultation_date, c.created_at)) FROM consultations c WHERE c.user_id = u.user_id AND {$completed}) as last_visit,
            (SELECT c.diagnosis FROM consultations c WHERE c.user_id = u.user_id AND {$completed} AND c.diagnosis IS NOT NULL AND TRIM(c.diagnosis) <> '' ORDER BY COALESCE(c.consultation_date, c.created_at) DESC, c.id DESC LIMIT 1) as recent_diagnosis,
            (SELECT COUNT(*) FROM follow_up_reminders f WHERE f.user_id = u.user_id AND LOWER(COALESCE(f.status,'')) NOT IN ('completed','done','resolved','cancelled')) as follow_ups
        ")
            ->orderBy('u.last_name')
            ->orderBy('u.first_name');

        $rows = $query->paginate($perPage);

        $rows->getCollection()->transform(function ($r) {
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
            return [
                'user_id' => (int) $r->user_id,
                'name' => $name !== '' ? $name : ('Patient #' . $r->user_id),
                'barangay' => $r->barangay,
                'rhu_id' => $r->rhu_id !== null ? (int) $r->rhu_id : null,
                'sex' => $r->sex,
                'age' => $this->ageFromBirthdate($r->birth_date),
                'mobile_number' => $r->mobile_number,
                'total_visits' => (int) ($r->total_visits ?? 0),
                'last_visit' => $r->last_visit,
                'recent_diagnosis' => $r->recent_diagnosis,
                'follow_ups' => (int) ($r->follow_ups ?? 0),
            ];
        });

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    private function ageFromBirthdate($birthdate): ?int
    {
        if (!$birthdate) {
            return null;
        }
        try {
            return (int) \Carbon\Carbon::parse($birthdate)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    public function profile(Request $request, int $userId): JsonResponse
    {
        $viewer = $request->user();

        $service = app(\App\Services\Reports\DiagnosisItrReportService::class);
        $rows = $service->rows(['user_id' => $userId], $viewer); // RHU-scoped by viewer

        $user = DB::table('users')->where('user_id', $userId)->first();

        if (!$user) {
            return response()->json(['message' => 'Patient not found.'], 404);
        }

        $patientRhu = \App\Support\Rhu::resolveRhuIdFromUser(\App\Models\User::find($userId));

        // If there is no history the viewer may see AND the patient is outside the
        // viewer's RHU, do not reveal them at all.
        if ($rows->isEmpty() && !\App\Support\Rhu::canAccessRhu($viewer, $patientRhu)) {
            return response()->json(['message' => 'This patient is not in your RHU.'], 403);
        }

        $mostRecent = $rows->first();

        $recentDiagnosis = $rows
            ->first(fn ($r) => trim((string) ($r['diagnosis'] ?? '')) !== '');

        $identity = [
            'user_id' => (int) $userId,
            'name' => $mostRecent['patient_name']
                ?? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                ?: ('Patient #' . $userId),
            'age' => $mostRecent['age'] ?? null,
            'sex' => $mostRecent['sex_gender'] ?? null,
            'barangay' => $mostRecent['barangay'] ?? ($user->barangay ?? null),
            'mobile_number' => $mostRecent['mobile_number'] ?? ($user->mobile_number ?? null),
            'philhealth_id' => $mostRecent['philhealth_id'] ?? null,
            'rhu_id' => $patientRhu,
        ];

        return response()->json([
            'data' => [
                'patient' => $identity,
                'summary' => [
                    'total_visits' => $rows->count(),
                    'last_visit' => $mostRecent['consultation_date']
                        ?? ($mostRecent['completed_at'] ?? null),
                    'recent_diagnosis' => $recentDiagnosis['diagnosis'] ?? null,
                    'follow_ups' => $rows
                        ->filter(fn ($r) => (bool) ($r['follow_up_needed'] ?? false))
                        ->count(),
                ],
                // Already date-descending from the service; each row carries the
                // full ITR/SOAP field set the Reports module exposes.
                'consultations' => $rows->values(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('residentProfile');

        return response()->json([
            'data' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'mobile_number' => $user->mobile_number,
                'barangay' => $user->barangay,
                'birthday' => $user->birthday?->toDateString(),
                'sex' => $user->sex,
                'id_verified' => (bool) $user->id_verified,
                'account_status' => $user->account_status,
                'avatar' => $user->profile_picture_url ?? $user->avatar,
                'philhealth_number' => $user->residentProfile?->philhealth_number,
            ],
        ]);
    }

    // =========================================================================
    // PATCH /api/v1/patient/me
    // Resident updates their own profile
    // =========================================================================

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'mobile_number' => ['sometimes', 'string', 'max:20'],
            'barangay' => ['sometimes', 'string', 'max:100'],
            'birthday' => ['sometimes', 'date'],
            'sex' => ['sometimes', 'in:male,female,other'],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated.',
            'data' => $user->fresh('residentProfile'),
        ]);
    }
}