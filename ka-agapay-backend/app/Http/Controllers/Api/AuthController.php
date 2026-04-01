<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $residentRole = UserRole::where('name', 'resident')->first();

        if (!$residentRole) {
            return response()->json(['message' => 'Default role not found. Run seeders first.'], 500);
        }

        $user = User::create([
            'role_id'        => $residentRole->role_id,
            'barangay_id'    => $request->barangay_id,
            'first_name'     => $request->first_name,
            'last_name'      => $request->last_name,
            'email'          => $request->email,
            'mobile_number'  => $request->mobile_number,
            'password'       => $request->password,
            'account_status' => 'active',
        ]);

        $token = $user->createToken('ka-agapay-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user'    => $this->formatUser($user),
            'token'   => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('mobile_number', $request->mobile_number)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->account_status !== 'active') {
            return response()->json(['message' => 'Account is not active.'], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('ka-agapay-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->formatUser($user),
            'token'   => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'barangay', 'residentProfile']);

        return response()->json(['user' => $this->formatUser($user)]);
    }

    private function formatUser(User $user): array
    {
        return [
            'user_id'        => $user->user_id,
            'first_name'     => $user->first_name,
            'last_name'      => $user->last_name,
            'email'          => $user->email,
            'mobile_number'  => $user->mobile_number,
            'account_status' => $user->account_status,
            'role'           => $user->role?->name,
            'barangay'       => $user->barangay?->name,
        ];
    }
}