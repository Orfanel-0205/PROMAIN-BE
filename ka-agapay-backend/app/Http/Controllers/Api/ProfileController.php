<?php
// app/Http/Controllers/Api/ProfileController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResidentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * PATCH /api/v1/profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name'    => ['sometimes', 'string', 'max:100'],
            'last_name'     => ['sometimes', 'string', 'max:100'],
            'email'         => [
                'sometimes',
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'mobile_number' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('users', 'mobile_number')->ignore($user->user_id, 'user_id'),
            ],

            // Mobile ProfileScreen may send barangay name.
            'barangay'      => ['sometimes', 'nullable', 'string', 'max:150'],

            // Future-proof: allow barangay_id too.
            'barangay_id'   => ['sometimes', 'nullable', 'integer', Rule::exists('barangays', 'barangay_id')],

            'birthday'      => ['sometimes', 'nullable', 'date'],
            'birth_date'    => ['sometimes', 'nullable', 'date'],
            'sex'           => ['sometimes', 'nullable', Rule::in(['male', 'female', 'other'])],
        ]);

        $barangay = $this->resolveBarangay($validated);

        $userUpdates = collect($validated)
            ->only(['first_name', 'last_name', 'email', 'mobile_number', 'sex'])
            ->toArray();

        if (array_key_exists('birthday', $validated) || array_key_exists('birth_date', $validated)) {
            $userUpdates['birthday'] = $validated['birthday'] ?? $validated['birth_date'] ?? null;
        }

        if ($barangay) {
            $userUpdates['barangay'] = $barangay->name;
        }

        if (!empty($userUpdates)) {
            $user->update($userUpdates);
        }

        $profileUpdates = [];

        if ($barangay) {
            $profileUpdates['barangay_id'] = (int) $barangay->barangay_id;
        }

        if (array_key_exists('first_name', $validated)) {
            $profileUpdates['first_name'] = $validated['first_name'];
        }

        if (array_key_exists('last_name', $validated)) {
            $profileUpdates['last_name'] = $validated['last_name'];
        }

        if (array_key_exists('mobile_number', $validated)) {
            $profileUpdates['mobile_number'] = $validated['mobile_number'];
        }

        if (array_key_exists('sex', $validated)) {
            $profileUpdates['sex'] = $validated['sex'];
        }

        if (array_key_exists('birthday', $validated) || array_key_exists('birth_date', $validated)) {
            $birthDate = $validated['birthday'] ?? $validated['birth_date'] ?? null;
            $profileUpdates['birth_date'] = $birthDate;
            $profileUpdates['birthdate'] = $birthDate;
        }

        if (!empty($profileUpdates)) {
            ResidentProfile::updateOrCreate(
                ['user_id' => $user->user_id],
                $profileUpdates
            );
        }

        $user->refresh();

        return response()->json([
            'message' => 'Profile updated.',
            'user'    => $this->formatUser($user),
        ]);
    }

    /**
     * POST /api/v1/profile/avatar
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

        if ($user->profile_picture) {
            Storage::disk('public')->delete($user->profile_picture);
        }

        $path = $request->file('avatar')->store(
            "avatars/{$user->user_id}",
            'public'
        );

        $url = Storage::disk('public')->url($path);

        $user->update([
            'profile_picture' => $path,
            'avatar'          => $url,
        ]);

        return response()->json([
            'message'    => 'Avatar uploaded successfully.',
            'avatar_url' => $url,
        ]);
    }

    private function resolveBarangay(array $validated): ?object
    {
        if (array_key_exists('barangay_id', $validated) && $validated['barangay_id']) {
            return DB::table('barangays')
                ->where('barangay_id', (int) $validated['barangay_id'])
                ->first();
        }

        if (!array_key_exists('barangay', $validated)) {
            return null;
        }

        $name = trim((string) ($validated['barangay'] ?? ''));

        if ($name === '') {
            return null;
        }

        return DB::table('barangays')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function formatUser($user): array
    {
        $profile = DB::table('resident_profiles as rp')
            ->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
            ->select(
                'rp.barangay_id',
                'b.name as barangay',
                'rp.birth_date',
                'rp.birthdate',
                'rp.date_of_birth',
                'rp.sex'
            )
            ->where('rp.user_id', $user->user_id)
            ->first();

        $birthday = $profile->birth_date
            ?? $profile->birthdate
            ?? $profile->date_of_birth
            ?? $user->birthday
            ?? null;

        return [
            'user_id'           => $user->user_id,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'email'             => $user->email,
            'mobile_number'     => $user->mobile_number,
            'barangay_id'       => $profile?->barangay_id ? (int) $profile->barangay_id : null,
            'barangay'          => $profile?->barangay ?: $user->barangay,
            'birthday'          => $this->parseDate($birthday),
            'sex'               => $profile?->sex ?: $user->sex,
            'account_status'    => $user->account_status,
            'role'              => $user->role?->name,
            'id_verified'       => (bool) $user->id_verified,
            'biometric_enabled' => (bool) $user->biometric_enabled,
            'avatar'            => $user->profile_picture_url ?? $user->avatar,
            'profile_picture'   => $user->profile_picture,
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}