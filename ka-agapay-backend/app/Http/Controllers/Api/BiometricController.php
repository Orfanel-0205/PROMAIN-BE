<?php
// app/Http/Controllers/Api/BiometricController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BiometricAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricController extends Controller
{
    public function __construct(
        private readonly BiometricAuthService $biometrics
    ) {}

    /**
     * POST /api/v1/biometric/enable
     *
     * Requires a normal Sanctum login token.
     * Returns a raw 64-character biometric exchange token.
     * Store this token only in Expo SecureStore on the phone.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $rawToken = $this->biometrics->enable($user, $request);

        return response()->json([
            'message' => 'Biometric login enabled.',
            'biometric_token' => $rawToken,
        ]);
    }

    /**
     * POST /api/v1/biometric/disable
     *
     * Revokes all biometric device tokens for the current user.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $this->biometrics->disableAll($user);

        return response()->json([
            'message' => 'Biometric login disabled.',
        ]);
    }
}
