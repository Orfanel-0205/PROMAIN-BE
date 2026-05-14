<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class BiometricController extends Controller
{
    /**
     * Enable biometric login for the authenticated user.
     * 
     * POST /api/v1/biometric/enable
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate([
            'biometric_token' => ['required', 'string', 'min:32'],
        ]);

        $user = $request->user();

        $user->update([
            'biometric_enabled'    => true,
            'biometric_token_hash' => Hash::make($request->biometric_token),
        ]);

        return response()->json([
            'message' => 'Biometric login enabled successfully.',
            'data'    => [
                'biometric_enabled' => true,
            ]
        ]);
    }

    /**
     * Disable biometric login for the authenticated user.
     * 
     * POST /api/v1/biometric/disable
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'biometric_enabled'    => false,
            'biometric_token_hash' => null,
        ]);

        return response()->json([
            'message' => 'Biometric login disabled successfully.',
            'data'    => [
                'biometric_enabled' => false,
            ]
        ]);
    }
}
