<?php
// app/Http/Controllers/Api/ResidentProfileController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResidentProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->residentProfile()->with('barangay')->first();

        if (!$profile) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        return response()->json(['profile' => $profile]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barangay_id'   => 'nullable|exists:barangays,barangay_id',
            'birth_date'    => 'nullable|date',
            'sex'           => 'nullable|in:male,female,other',
            'address'       => 'nullable|string',
            'philhealth_no' => 'nullable|string|max:50',
        ]);

        $profile = $request->user()->residentProfile()->updateOrCreate(
            ['user_id' => $request->user()->user_id],
            $validated
        );

        return response()->json(['message' => 'Profile updated.', 'profile' => $profile]);
    }
}