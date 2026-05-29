<?php
// app/Http/Controllers/Api/PatientController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    // =========================================================================
    // GET /patient/me  — resident reads their own profile
    // =========================================================================

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('residentProfile');

        return response()->json([
            'data' => [
                'user_id'          => $user->user_id,
                'first_name'       => $user->first_name,
                'last_name'        => $user->last_name,
                'email'            => $user->email,
                'mobile_number'    => $user->mobile_number,
                'barangay'         => $user->barangay,
                'birthday'         => $user->birthday?->toDateString(),
                'sex'              => $user->sex,
                'id_verified'      => (bool) $user->id_verified,
                'account_status'   => $user->account_status,
                'avatar'           => $user->profile_picture_url ?? $user->avatar,
                'philhealth_number'=> $user->residentProfile?->philhealth_number,
            ],
        ]);
    }

    // =========================================================================
    // PATCH /patient/me  — resident updates their own profile
    // =========================================================================

    public function update(Request $request): JsonResponse
    {
        $user      = $request->user();
        $validated = $request->validate([
            'first_name'    => ['sometimes', 'string', 'max:100'],
            'last_name'     => ['sometimes', 'string', 'max:100'],
            'mobile_number' => ['sometimes', 'string', 'max:20'],
            'barangay'      => ['sometimes', 'string', 'max:100'],
            'birthday'      => ['sometimes', 'date'],
            'sex'           => ['sometimes', 'in:male,female,other'],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated.',
            'data'    => $user->fresh('residentProfile'),
        ]);
    }
}