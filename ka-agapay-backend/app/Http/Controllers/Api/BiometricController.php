<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class BiometricController extends Controller
{
    // Token name used to identify biometric tokens in personal_access_tokens
    private const BIOMETRIC_TOKEN_NAME = 'ka-agapay-biometric';

    /**
     * Enable biometric login.
     *
     * Issues a DEDICATED long-lived Sanctum token specifically for
     * biometric auth and returns it to the mobile app for SecureStore.
     *
     * POST /api/v1/biometric/enable
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke any previously issued biometric token before creating a new one.
        // This handles "re-register biometrics" without leaving orphaned tokens.
        $user->tokens()
            ->where('name', self::BIOMETRIC_TOKEN_NAME)
            ->delete();

        // Issue a dedicated biometric token (separate from the session token).
        // This token survives password logins because AuthController::login()
        // will be updated to skip tokens with this name.
        $biometricToken = $user->createToken(self::BIOMETRIC_TOKEN_NAME)
            ->plainTextToken;

        $user->update([
            'biometric_enabled'    => true,
            // Hash stored for audit/verification purposes
            'biometric_token_hash' => Hash::make($biometricToken),
        ]);

        return response()->json([
            'message'         => 'Biometric login enabled successfully.',
            // Return the token so the mobile app stores it in SecureStore
            'biometric_token' => $biometricToken,
        ]);
    }

    /**
     * Disable biometric login.
     *
     * POST /api/v1/biometric/disable
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke the dedicated biometric Sanctum token so it can no longer
        // be used to call /me even if it is still in the device's SecureStore.
        $user->tokens()
            ->where('name', self::BIOMETRIC_TOKEN_NAME)
            ->delete();

        $user->update([
            'biometric_enabled'    => false,
            'biometric_token_hash' => null,
        ]);

        return response()->json([
            'message' => 'Biometric login disabled successfully.',
        ]);
    }
}