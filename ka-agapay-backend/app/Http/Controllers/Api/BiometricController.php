<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BiometricController extends Controller
{
    /**
     * POST /api/v1/biometric/enable
     *
     * Requires normal Sanctum login token.
     * Returns a raw 64-character biometric exchange token.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        $rawToken = Str::random(64);

        $user->forceFill([
            'biometric_enabled' => true,
            'biometric_token_hash' => Hash::make($rawToken),
        ])->save();

        return response()->json([
            'message' => 'Biometric login enabled.',
            'biometric_token' => $rawToken,
        ]);
    }

    /**
     * POST /api/v1/biometric/disable
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'biometric_enabled' => false,
            'biometric_token_hash' => null,
        ])->save();

        return response()->json([
            'message' => 'Biometric login disabled.',
        ]);
    }
}