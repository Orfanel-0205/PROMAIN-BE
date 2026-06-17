<?php
// app/Http/Controllers/Api/PatientController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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