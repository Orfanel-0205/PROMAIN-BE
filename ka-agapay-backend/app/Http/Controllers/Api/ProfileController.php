<?php
// app/Http/Controllers/Api/ProfileController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('role');

        return response()->json([
            'data' => $this->profilePayload($user),
            'user' => $this->profilePayload($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:180'],

            'email' => [
                'nullable',
                'email',
                'max:180',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],

            'mobile_number' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('users', 'mobile_number')->ignore($user->user_id, 'user_id'),
            ],

            'phone' => ['nullable', 'string', 'max:30'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'birthday' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'sex' => ['nullable', 'string', 'max:30'],
        ]);

        $updates = [];

        if (!empty($validated['name']) && empty($validated['first_name']) && empty($validated['last_name'])) {
            [$firstName, $lastName] = $this->splitName($validated['name']);

            $updates['first_name'] = $firstName;
            $updates['last_name'] = $lastName;
        }

        foreach ([
            'first_name',
            'last_name',
            'email',
            'barangay',
            'sex',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if (array_key_exists('mobile_number', $validated) || array_key_exists('phone', $validated)) {
            $mobile = $this->normalizeMobileNumber(
                $validated['mobile_number'] ?? $validated['phone'] ?? null
            );

            if ($mobile !== '') {
                abort_unless(
                    preg_match('/^09\d{9}$/', $mobile) === 1,
                    422,
                    'Mobile number must use this format: 09XXXXXXXXX.'
                );
            }

            $updates['mobile_number'] = $mobile;
        }

        if (array_key_exists('birthday', $validated) || array_key_exists('birth_date', $validated)) {
            $updates['birthday'] = $validated['birthday'] ?? $validated['birth_date'] ?? null;
        }

        if (!empty($updates)) {
            $user->update($updates);
        }

        $fresh = $user->fresh()->loadMissing('role');

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $this->profilePayload($fresh),
            'user' => $this->profilePayload($fresh),
        ]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $file =
            $request->file('avatar')
            ?? $request->file('profile_picture')
            ?? $request->file('photo');

        abort_unless($file, 422, 'Please upload a valid profile picture.');

        $oldProfilePicture = $user->profile_picture;
        $oldAvatar = $user->avatar;

        $path = $file->store('profile-pictures', 'public');

        $updates = [];

        if (Schema::hasColumn('users', 'profile_picture')) {
            $updates['profile_picture'] = $path;
        }

        if (Schema::hasColumn('users', 'avatar')) {
            $updates['avatar'] = $path;
        }

        if (!empty($updates)) {
            $user->forceFill($updates)->save();
        }

        $this->deleteOldProfilePicture($oldProfilePicture, $path);
        $this->deleteOldProfilePicture($oldAvatar, $path);

        $fresh = $user->fresh()->loadMissing('role');

        return response()->json([
            'message' => 'Profile picture updated successfully.',
            'avatar_url' => $this->publicFileUrl($path),
            'profile_picture_url' => $this->publicFileUrl($path),
            'data' => $this->profilePayload($fresh),
            'user' => $this->profilePayload($fresh),
        ]);
    }

    private function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $firstName = array_shift($parts) ?: '';
        $lastName = implode(' ', $parts);

        return [$firstName, $lastName];
    }

    private function normalizeMobileNumber(?string $mobile): string
    {
        $mobile = preg_replace('/[^\d+]/', '', trim((string) $mobile)) ?? '';

        if (str_starts_with($mobile, '+63')) {
            $mobile = '0' . substr($mobile, 3);
        }

        if (str_starts_with($mobile, '63') && strlen($mobile) === 12) {
            $mobile = '0' . substr($mobile, 2);
        }

        return $mobile;
    }

    private function profilePayload(User $user): array
    {
        $user->loadMissing('role');

        $fullName = trim((string) $user->first_name . ' ' . (string) $user->last_name);
        $avatarPath = $user->profile_picture ?: $user->avatar;

        return [
            'id' => $user->user_id,
            'user_id' => $user->user_id,

            'name' => $fullName,
            'full_name' => $fullName,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,

            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'phone' => $user->mobile_number,

            'barangay' => $user->barangay,
            'birthday' => optional($user->birthday)->toDateString(),
            'sex' => $user->sex,

            'role' => $this->normalizeRoleName($this->resolveRoleName($user)),
            'role_name' => $this->normalizeRoleName($this->resolveRoleName($user)),
            'role_id' => $user->role_id,

            'account_status' => $user->account_status,
            'status' => $user->account_status,

            'id_verified' => (bool) $user->id_verified,
            'staff_approved_by' => $user->staff_approved_by,
            'staff_approved_at' => optional($user->staff_approved_at)->toISOString(),

            'avatar' => $avatarPath,
            'profile_picture' => $user->profile_picture,
            'avatar_url' => $this->publicFileUrl($avatarPath),
            'profile_picture_url' => $this->publicFileUrl($avatarPath),

            'created_at' => optional($user->created_at)->toISOString(),
            'updated_at' => optional($user->updated_at)->toISOString(),
        ];
    }

    private function normalizeRoleName(?string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $role)));
    }

    private function resolveRoleName(User $user): string
    {
        if (is_object($user->role ?? null)) {
            foreach (['name', 'role_name', 'slug', 'role', 'title', 'code'] as $field) {
                if (!empty($user->role->{$field})) {
                    return (string) $user->role->{$field};
                }
            }
        }

        return (string) ($user->role_name ?? 'resident');
    }

    private function publicFileUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    private function deleteOldProfilePicture(?string $oldPath, string $newPath): void
    {
        if (!$oldPath || $oldPath === $newPath) {
            return;
        }

        if (str_starts_with($oldPath, 'http://') || str_starts_with($oldPath, 'https://')) {
            return;
        }

        try {
            Storage::disk('public')->delete($oldPath);
        } catch (\Throwable) {
            // Do not fail upload if old image cannot be deleted.
        }
    }
}