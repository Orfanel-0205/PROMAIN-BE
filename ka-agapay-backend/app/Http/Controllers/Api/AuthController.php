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
        $role = UserRole::where('name', 'resident')->first();

        if (!$role) {
            return response()->json([
                'message' => 'Default role not found. Please seed the user_roles table.',
            ], 500);
        }

        $user = User::create([
            'role_id'        => $role->role_id,
            'first_name'     => $request->first_name,
            'last_name'      => $request->last_name,
            'email'          => $request->email,
            'mobile_number'  => $request->mobile_number,
            'password'       => Hash::make($request->password),
            'barangay'       => $request->barangay,   // plain string column
            'birthday'       => $request->birthday,
            'sex'            => $request->sex,
            'account_status' => 'active',              // skip approval, go straight in
        ]);

        // Only load 'role' — no barangay relationship on User
        $user->load('role');

        $token = $user->createToken('ka-agapay-mobile')->plainTextToken;

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
            return response()->json([
                'message' => 'Invalid mobile number or password.',
            ], 401);
        }

        if (in_array($user->account_status, ['suspended', 'rejected'])) {
            return response()->json([
                'message' => 'Your account has been ' . $user->account_status . '.',
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('ka-agapay-mobile')->plainTextToken;

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $user->load('role');

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
        $user = $request->user()->load('role');
        return response()->json(['user' => $this->formatUser($user)]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name'  => ['sometimes', 'string', 'max:100'],
            'last_name'   => ['sometimes', 'string', 'max:100'],
            'email'       => ['sometimes', 'email', "unique:users,email,{$user->user_id},user_id"],
            'barangay'    => ['sometimes', 'string'],
        ]);

        $user->update($validated);
        $user->load('role');

        return response()->json([
            'message' => 'Profile updated.',
            'user'    => $this->formatUser($user),
        ]);
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
            'role'           => $user->role?->name ?? 'resident',
            'barangay'       => $user->barangay,   // plain string, no relationship
            'avatar'         => $user->avatar ?? null,
            'birthday'       => $user->birthday,
            'sex'            => $user->sex,
        ];
    }
}