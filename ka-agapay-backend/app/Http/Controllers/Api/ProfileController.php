<?php
// app/Http/Controllers/Api/ProfileController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResidentProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Reusable, NON-CLINICAL patient ITR details that live on resident_profiles
     * and may be edited by the patient. These are self-reported and are re-used
     * across appointments / queue / consultations.
     *
     * Vitals, diagnosis, assessment, plan, prescription, and lab fields are
     * intentionally NOT here — those are staff-only and live elsewhere.
     */
    private const PROFILE_FIELDS = [
        'middle_name',
        'civil_status',
        'religion',
        'educational_attainment',
        'occupation',
        'client_type',
        'guardian_name',
        'guardian_birthdate',
        'emergency_contact_name',
        'emergency_contact_number',
        'philhealth_number',
        'blood_type',
        'address',
        'street',
        'purok',
        'household_number',
        'allergies',
        'past_medical_history',
        'maintenance_medications',
        'family_history',
        'personal_social_history',
        'smoking_status',
        'alcohol_intake',
        'lmp',
        'menstrual_history',
        'family_planning_method',
        'pregnancy_history',
        'number_of_children',
        'period_duration',
        'cycle',
        'menopausal_age',
    ];
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('role');

        $profile = $this->residentProfileFor($user);
        $payload = $this->profilePayload($user, $profile);

        return response()->json([
            'data' => $payload,
            'user' => $payload,
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

            // Reusable patient ITR profile details (non-clinical, patient-editable)
            'middle_name' => ['nullable', 'string', 'max:100'],
            'guardian_birthdate' => ['nullable', 'date'],
            'blood_type' => ['nullable', 'string', 'max:10'],
            'number_of_children' => ['nullable', 'string', 'max:20'],
            'period_duration' => ['nullable', 'string', 'max:50'],
            'cycle' => ['nullable', 'string', 'max:50'],
            'menopausal_age' => ['nullable', 'string', 'max:20'],
            'civil_status' => ['nullable', 'string', 'max:50'],
            'religion' => ['nullable', 'string', 'max:100'],
            'educational_attainment' => ['nullable', 'string', 'max:100'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'client_type' => ['nullable', 'string', 'max:50'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_number' => ['nullable', 'string', 'max:30'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'street' => ['nullable', 'string', 'max:150'],
            'purok' => ['nullable', 'string', 'max:100'],
            'household_number' => ['nullable', 'string', 'max:50'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'past_medical_history' => ['nullable', 'string', 'max:2000'],
            'maintenance_medications' => ['nullable', 'string', 'max:2000'],
            'family_history' => ['nullable', 'string', 'max:2000'],
            'personal_social_history' => ['nullable', 'string', 'max:2000'],
            'smoking_status' => ['nullable', 'string', 'max:30'],
            'alcohol_intake' => ['nullable', 'string', 'max:30'],
            'lmp' => ['nullable', 'date'],
            'menstrual_history' => ['nullable', 'string', 'max:2000'],
            'family_planning_method' => ['nullable', 'string', 'max:100'],
            'pregnancy_history' => ['nullable', 'string', 'max:2000'],
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

        $profile = $this->persistProfileFields($user, $validated);

        $fresh = $user->fresh()->loadMissing('role');
        $payload = $this->profilePayload($fresh, $profile);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $payload,
            'user' => $payload,
        ]);
    }

    /**
     * Persist reusable ITR profile fields to resident_profiles.
     * Only writes columns that actually exist (the table varied across
     * deployments), and only fields present in this request.
     */
    private function persistProfileFields(User $user, array $validated): ?ResidentProfile
    {
        if (!Schema::hasTable('resident_profiles')) {
            return null;
        }

        $profileData = [];

        foreach (self::PROFILE_FIELDS as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            if (!Schema::hasColumn('resident_profiles', $field)) {
                continue;
            }

            $value = $validated[$field];
            $profileData[$field] = ($value === '') ? null : $value;
        }

        // Keep the canonical philhealth columns in sync when only one is sent.
        if (
            array_key_exists('philhealth_number', $profileData)
            && Schema::hasColumn('resident_profiles', 'philhealth_no')
            && !array_key_exists('philhealth_no', $profileData)
        ) {
            $profileData['philhealth_no'] = $profileData['philhealth_number'];
        }

        if (empty($profileData)) {
            return $this->residentProfileFor($user);
        }

        return ResidentProfile::updateOrCreate(
            ['user_id' => $user->user_id],
            $profileData
        );
    }

    private function residentProfileFor(User $user): ?ResidentProfile
    {
        if (!Schema::hasTable('resident_profiles')) {
            return null;
        }

        return ResidentProfile::where('user_id', $user->user_id)->first();
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

    private function profilePayload(User $user, ?ResidentProfile $profile = null): array
    {
        $user->loadMissing('role');

        $profile = $profile ?? $this->residentProfileFor($user);

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

            // Reusable patient ITR details (from resident_profiles)
            'middle_name' => $profile?->middle_name,
            'civil_status' => $profile?->civil_status,
            'religion' => $profile?->religion,
            'educational_attainment' => $profile?->educational_attainment,
            'occupation' => $profile?->occupation,
            'client_type' => $profile?->client_type,
            'guardian_name' => $profile?->guardian_name,
            'guardian_birthdate' => optional($profile?->guardian_birthdate)->toDateString(),
            'emergency_contact_name' => $profile?->emergency_contact_name,
            'emergency_contact_number' => $profile?->emergency_contact_number,
            'philhealth_number' => $profile?->philhealth_number ?? $profile?->philhealth_no,
            'philhealth_verified_at' => optional($profile?->philhealth_verified_at)->toIso8601String(),
            'philhealth_name_matched' => $profile?->philhealth_name_matched,
            'blood_type' => $profile?->blood_type,
            'address' => $profile?->address,
            'street' => $profile?->street,
            'purok' => $profile?->purok,
            'household_number' => $profile?->household_number,
            'allergies' => $profile?->allergies,
            'past_medical_history' => $profile?->past_medical_history,
            'maintenance_medications' => $profile?->maintenance_medications,
            'family_history' => $profile?->family_history,
            'personal_social_history' => $profile?->personal_social_history,
            'smoking_status' => $profile?->smoking_status,
            'alcohol_intake' => $profile?->alcohol_intake,
            'lmp' => optional($profile?->lmp)->toDateString(),
            'menstrual_history' => $profile?->menstrual_history,
            'family_planning_method' => $profile?->family_planning_method,
            'pregnancy_history' => $profile?->pregnancy_history,
            'number_of_children' => $profile?->number_of_children,
            'period_duration' => $profile?->period_duration,
            'cycle' => $profile?->cycle,
            'menopausal_age' => $profile?->menopausal_age,

            // Mandatory-profile gating for consultation booking (ITR readiness).
            'profile_completion' => $this->profileCompletionFor($user, $profile),

            'created_at' => optional($user->created_at)->toISOString(),
            'updated_at' => optional($user->updated_at)->toISOString(),
        ];
    }

    /**
     * Compute whether the resident has the minimum ITR profile fields required
     * to book a consultation. Safe against missing columns / missing profile;
     * supports legacy field names; never blocks login — only informs the app.
     *
     * PhilHealth is reported as a non-blocking warning (the system still allows
     * patients without PhilHealth), so it does NOT count toward can_book.
     */
    public function profileCompletionFor(User $user, ?ResidentProfile $profile = null): array
{
    $profile = $profile ?? $this->residentProfileFor($user);

    // First non-empty value across candidate [source, attribute] pairs.
    $val = function (array $candidates) use ($user, $profile): ?string {
        foreach ($candidates as [$source, $attr]) {
            $obj = $source === 'user' ? $user : $profile;

            if (!$obj) {
                continue;
            }

            $raw = $obj->getAttribute($attr);
            $value = trim((string) ($raw ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    };

    /*
     * Required fields for booking/ITR readiness.
     *
     * IMPORTANT:
     * Guardian / Emergency Contact is OPTIONAL.
     * PhilHealth is also OPTIONAL unless the user has OCR-verified it.
     */
    $checks = [
        'first_name' => ['First Name', [
            ['user', 'first_name'],
            ['profile', 'first_name'],
        ]],

        'last_name' => ['Last Name', [
            ['user', 'last_name'],
            ['profile', 'last_name'],
        ]],

        'birth_date' => ['Birth Date', [
            ['user', 'birthday'],
            ['user', 'birth_date'],
            ['profile', 'birth_date'],
            ['profile', 'birthdate'],
            ['profile', 'date_of_birth'],
        ]],

        'gender' => ['Gender / Sex', [
            ['user', 'sex'],
            ['user', 'gender'],
            ['profile', 'sex'],
            ['profile', 'gender'],
        ]],

        'civil_status' => ['Civil Status', [
            ['profile', 'civil_status'],
            ['user', 'civil_status'],
        ]],

        'mobile_number' => ['Mobile Number', [
            ['user', 'mobile_number'],
            ['profile', 'mobile_number'],
            ['profile', 'contact_number'],
            ['profile', 'phone_number'],
        ]],

        'barangay' => ['Barangay', [
            ['profile', 'barangay_id'],
            ['user', 'barangay_id'],
            ['user', 'barangay'],
        ]],

        'address' => ['Address', [
            ['profile', 'address'],
            ['user', 'address'],
        ]],
    ];

    $missingFields = [];
    $missingLabels = [];
    $filled = 0;

    foreach ($checks as $key => [$label, $candidates]) {
        if ($val($candidates) !== null) {
            $filled++;
        } else {
            $missingFields[] = $key;
            $missingLabels[] = $label;
        }
    }

    $total = count($checks);
    $percent = $total > 0 ? (int) round(($filled / $total) * 100) : 100;
    $isComplete = count($missingFields) === 0;

    // Optional guardian/emergency contact.
    $guardianName = $val([
        ['profile', 'guardian_name'],
        ['profile', 'emergency_contact_name'],
    ]);

    $guardianContact = $val([
        ['profile', 'guardian_contact'],
        ['profile', 'emergency_contact_number'],
        ['profile', 'emergency_contact'],
    ]);

    // PhilHealth is informational only and should not block booking.
    $philhealthNumber = $val([
        ['profile', 'philhealth_number'],
        ['profile', 'philhealth_no'],
        ['profile', 'philhealth_pin'],
    ]);

    $philhealthVerified = (bool) ($profile && $profile->getAttribute('philhealth_verified_at'));

    return [
        'is_complete' => $isComplete,
        'can_book_consultation' => $isComplete,
        'percent' => $percent,

        'missing_fields' => $missingFields,
        'missing_labels' => $missingLabels,

        'guardian_optional' => true,
        'guardian_present' => $guardianName !== null || $guardianContact !== null,

        'philhealth_present' => $philhealthNumber !== null,
        'philhealth_verified' => $philhealthVerified,
        'philhealth_warning' => $philhealthVerified
            ? null
            : 'PhilHealth is not yet verified. You may continue if PhilHealth is not available.',

        'message' => $isComplete
            ? 'Your health profile is complete. You can book a consultation.'
            : 'Complete your required health profile details before booking a consultation.',
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
