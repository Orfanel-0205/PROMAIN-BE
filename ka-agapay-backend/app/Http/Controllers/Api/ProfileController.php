<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * PATCH /v1/profile
     * Update the authenticated user's profile fields.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name'    => 'sometimes|string|max:100',
            'last_name'     => 'sometimes|string|max:100',
            'email'         => 'sometimes|nullable|email|unique:users,email,' . $user->user_id . ',user_id',
            'mobile_number' => 'sometimes|string|max:20|unique:users,mobile_number,' . $user->user_id . ',user_id',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated.',
            'user'    => [
                'user_id'       => $user->user_id,
                'first_name'    => $user->first_name,
                'last_name'     => $user->last_name,
                'email'         => $user->email,
                'mobile_number' => $user->mobile_number,
                'barangay'      => $user->barangay ?? null,
                'avatar'        => $user->avatar   ?? null,
            ],
        ]);
    }

    /**
     * POST /v1/profile/avatar
     * Upload and update the user's profile photo.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // 5 MB max
        ]);

        $user = $request->user();

        // Delete old avatar if it exists in storage
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        // Store new avatar under avatars/{user_id}/
        $path = $request->file('avatar')->store(
            'avatars/' . $user->user_id,
            'public'
        );

        $user->update(['avatar' => $path]);

        return response()->json([
            'message'    => 'Avatar updated.',
            'avatar_url' => Storage::disk('public')->url($path),
        ]);
    }
}