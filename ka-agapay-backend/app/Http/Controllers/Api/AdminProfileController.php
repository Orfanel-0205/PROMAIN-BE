<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('role');

        return response()->json([
            'data' => $this->payload($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'nullable',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')->whereNull('deleted_at'),
            ],
            'mobile_number' => [
                'required',
                'regex:/^09\d{9}$/',
                Rule::unique('users', 'mobile_number')->ignore($user->user_id, 'user_id')->whereNull('deleted_at'),
            ],
            'barangay' => ['nullable', 'string', 'max:150'],
        ]);

        $updates = [
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'email' => $validated['email'] ?? null,
            'mobile_number' => $validated['mobile_number'],
        ];

        if (Schema::hasColumn('users', 'barangay')) {
            $updates['barangay'] = $validated['barangay'] ?? null;
        }

        $user->update($updates);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $this->payload($user->fresh()->load('role')),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    private function payload($user): array
    {
        $role = $user->role?->name ?? 'staff';

        $avatarPath = $user->avatar ?: ($user->profile_picture ?? null);
        $avatarUrl = $avatarPath
            ? (str_starts_with((string) $avatarPath, 'http')
                ? (string) $avatarPath
                : \Illuminate\Support\Facades\Storage::disk('public')->url($avatarPath))
            : null;

        return [
            'id' => $user->user_id,
            'user_id' => $user->user_id,
            'name' => trim($user->first_name . ' ' . $user->last_name),
            'avatar' => $avatarPath,
            'avatar_url' => $avatarUrl,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'phone' => $user->mobile_number,
            'barangay' => $user->barangay ?? null,
            'role' => $role,
            'role_label' => ucwords(str_replace('_', ' ', $role)),
            'account_status' => $user->account_status,
            'status' => $user->account_status,
            'id_verified' => (bool) ($user->id_verified ?? false),
            'created_at' => $user->created_at,
            'last_login_at' => $user->last_login_at ?? null,
            'capabilities' => $this->capabilities($role),
        ];
    }

    private function capabilities(string $role): array
    {
        return match ($role) {
            'super_admin', 'rhu_admin' => [
                'full_access',
                'cms',
                'analytics',
                'queue',
                'telemedicine',
                'user_management',
                'manage_sms',
            ],
            'mho', 'municipal_mayor', 'it_staff' => [
                'cms',
                'analytics',
                'queue',
                'telemedicine',
                'user_management',
            ],
            'doctor' => ['telemedicine', 'queue'],
            'nurse' => ['queue', 'telemedicine'],
            'midwife', 'bhw' => ['queue'],
            default => [],
        };
    }
}