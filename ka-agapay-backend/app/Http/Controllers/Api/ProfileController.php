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
        'avatar' => [
            'required',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:5120',
        ],
    ]);

    $user = $request->user();

    // Delete old avatar
    if ($user->profile_picture) {
        Storage::disk('public')
            ->delete($user->profile_picture);
    }

    // Store publicly
    $path = $request->file('avatar')->store(
        "avatars/{$user->user_id}",
        'public'
    );

    $url = Storage::disk('public')->url($path);

    // IMPORTANT:
    // Save BOTH fields
    $user->update([
        'profile_picture' => $path,
        'avatar'          => $url,
    ]);

    return response()->json([
        'message'    => 'Avatar uploaded successfully.',
        'avatar_url' => $url,
    ]);
}
}