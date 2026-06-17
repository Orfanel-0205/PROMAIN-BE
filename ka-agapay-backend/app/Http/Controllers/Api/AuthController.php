<?php
// app/Http/Controllers/Api/AuthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    private const ADMIN_ROLES = [
        'super_admin',
        'rhu_admin',
        'mho',
        'doctor',
        'nurse',
        'midwife',
        'bhw',
    ];

    private const ROLE_CAPABILITIES = [
        'super_admin' => ['full_access', 'cms', 'analytics', 'queue', 'telemedicine', 'user_management'],
        'rhu_admin'   => ['full_access', 'cms', 'analytics', 'queue', 'telemedicine', 'user_management'],
        'mho'         => ['cms', 'analytics', 'queue', 'telemedicine'],
        'doctor'      => ['telemedicine', 'queue'],
        'nurse'       => ['queue', 'telemedicine'],
        'midwife'     => ['queue'],
        'bhw'         => ['queue'],
    ];

    // =========================================================================
    // ADMIN WEB LOGIN  POST /api/v1/admin/login
    // =========================================================================

    public function adminLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string'],
            'password'      => ['required', 'string'],
        ]);

        $mobile = trim($validated['mobile_number']);
        $rateLimitKey = 'admin_login|' . $mobile . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::with('role')
            ->where('mobile_number', $mobile)
            ->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'Account temporarily locked. Try again after '
                    . $user->locked_until->diffForHumans() . '.',
            ], 423);
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, 60);

            if ($user) {
                $failCount = ((int) $user->failed_login_count) + 1;

                $updates = [
                    'failed_login_count' => $failCount,
                ];

                if ($failCount >= 10) {
                    $updates['locked_until'] = now()->addMinutes(30);
                }

                $user->update($updates);
            }

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $roleName = $user->role?->name;

        if (!in_array($roleName, self::ADMIN_ROLES, true)) {
            return response()->json([
                'message' => 'Access denied. This portal is for RHU staff only.',
            ], 403);
        }

        if ($user->account_status !== 'active') {
            return response()->json([
                'message' => match ($user->account_status) {
                    'pending'   => 'Your account is pending approval.',
                    'suspended' => 'Your account has been suspended.',
                    'rejected'  => 'Your registration was not approved.',
                    default     => 'Account is not active.',
                },
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);

        $user->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => now(),
            'last_login_ip'      => $request->ip(),
        ]);

        $token = $user->createToken('web-admin')->plainTextToken;

        $this->logActivity($user, 'ADMIN_LOGIN', [
            'method' => 'mobile_password',
            'role'   => $roleName,
        ], $request);

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->formatAdminUser($user, $roleName),
            'token'   => $token,
        ]);
    }

    // =========================================================================
    // MOBILE LOGIN  POST /api/v1/login
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string'],
            'password'      => ['required', 'string'],
        ]);

        $mobile = trim($validated['mobile_number']);
        $rateLimitKey = 'login|' . $mobile;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message' => "Too many login attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::with('role')
            ->where('mobile_number', $mobile)
            ->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'Account locked. Try again after '
                    . $user->locked_until->diffForHumans() . '.',
            ], 423);
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, 60);

            if ($user) {
                $failCount = ((int) $user->failed_login_count) + 1;

                $updates = [
                    'failed_login_count' => $failCount,
                ];

                if ($failCount >= 10) {
                    $updates['locked_until'] = now()->addMinutes(30);
                }

                $user->update($updates);
            }

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->account_status !== 'active') {
            return response()->json([
                'message' => match ($user->account_status) {
                    'pending'   => 'Your account is pending approval.',
                    'suspended' => 'Your account has been suspended. Contact the RHU.',
                    'rejected'  => 'Your registration was not approved.',
                    default     => 'Account is not active.',
                },
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);

        $user->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => now(),
            'last_login_ip'      => $request->ip(),
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        $this->logActivity($user, 'LOGIN', [
            'method' => 'mobile_password',
        ], $request);

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->formatUser($user),
            'token'   => $token,
        ]);
    }

    // =========================================================================
    // REGISTER  POST /api/v1/register
    // =========================================================================

    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'barangay'      => trim((string) $request->input('barangay', '')),
            'mobile_number' => trim((string) $request->input('mobile_number', '')),
        ]);

        $validated = $request->validate([
            'first_name'            => ['required', 'string', 'max:100'],
            'last_name'             => ['required', 'string', 'max:100'],
            'email'                 => ['nullable', 'email', 'unique:users,email', 'max:255'],
            'mobile_number'         => ['required', 'string', 'regex:/^09\d{9}$/', 'unique:users,mobile_number'],
            'password'              => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required'],

            // Mobile currently sends barangay name.
            'barangay'              => ['required_without:barangay_id', 'nullable', 'string', 'max:150', Rule::exists('barangays', 'name')],

            // Also accept barangay_id for future-proofing.
            'barangay_id'           => ['nullable', 'integer', Rule::exists('barangays', 'barangay_id')],

            'birthday'              => ['nullable', 'date', 'before:' . now()->subYears(18)->toDateString(), 'after:1900-01-01'],
            'birth_date'            => ['nullable', 'date', 'before:' . now()->subYears(18)->toDateString(), 'after:1900-01-01'],
            'sex'                   => ['nullable', Rule::in(['male', 'female', 'other'])],
        ]);

        $barangay = $this->resolveBarangayFromRequest($validated);

        if (!$barangay) {
            return response()->json([
                'message' => 'Invalid barangay.',
                'errors' => [
                    'barangay' => ['Please select a valid barangay.'],
                ],
            ], 422);
        }

        $residentRole = UserRole::where('name', 'resident')->firstOrFail();

        $user = DB::transaction(function () use ($validated, $residentRole, $barangay): User {
            $birthday = $validated['birthday'] ?? $validated['birth_date'] ?? null;

            $user = User::create([
                'role_id'           => $residentRole->role_id,
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email'] ?? null,
                'mobile_number'     => $validated['mobile_number'],
                'password'          => Hash::make($validated['password']),

                // Keep legacy text field for display compatibility.
                'barangay'          => $barangay->name,

                'birthday'          => $birthday,
                'sex'               => $validated['sex'] ?? null,
                'account_status'    => 'pending',
                'id_verified'       => false,
                'biometric_enabled' => false,
            ]);

            ResidentProfile::updateOrCreate(
                ['user_id' => $user->user_id],
                [
                    'barangay_id'   => (int) $barangay->barangay_id,
                    'first_name'    => $validated['first_name'],
                    'last_name'     => $validated['last_name'],
                    'mobile_number' => $validated['mobile_number'],
                    'birth_date'    => $birthday,
                    'birthdate'     => $birthday,
                    'sex'           => $validated['sex'] ?? null,
                ]
            );

            $user->refresh();

            return $user;
        });

        $token = $user->createToken('mobile')->plainTextToken;

        $this->logActivity($user, 'REGISTER', [
            'barangay' => $barangay->name,
            'barangay_id' => (int) $barangay->barangay_id,
        ], $request);

        return response()->json([
            'message' => 'Registration successful. Your account is pending approval.',
            'user'    => $this->formatUser($user),
            'token'   => $token,
        ], 201);
    }

    // =========================================================================
    // SHARED  POST /api/v1/logout
    // =========================================================================

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        $this->logActivity($request->user(), 'LOGOUT', [], $request);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    // =========================================================================
    // GET /api/v1/me
    // =========================================================================

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');
        $role = $user->role?->name;

        if (in_array($role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'user' => $this->formatAdminUser($user, $role),
            ]);
        }

        return response()->json([
            'user' => $this->formatUser($user),
        ]);
    }

    // =========================================================================
    // BIOMETRIC LOGIN  POST /api/v1/biometric/login
    // =========================================================================

    public function biometricLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'biometric_token' => ['required', 'string', 'size:64'],
        ]);

        $rateLimitKey = 'biometric_login|' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message' => "Too many biometric attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $rawToken = $validated['biometric_token'];

        $users = User::query()
            ->with('role')
            ->where('biometric_enabled', true)
            ->whereNotNull('biometric_token_hash')
            ->get();

        $matchedUser = null;

        foreach ($users as $user) {
            if (Hash::check($rawToken, $user->biometric_token_hash)) {
                $matchedUser = $user;
                break;
            }
        }

        if (!$matchedUser) {
            RateLimiter::hit($rateLimitKey, 60);

            return response()->json([
                'message' => 'Invalid or expired biometric token.',
            ], 401);
        }

        if (
            isset($matchedUser->account_status) &&
            !in_array($matchedUser->account_status, ['approved', 'active'], true)
        ) {
            return response()->json([
                'message' => 'Your account is pending approval.',
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);

        $matchedUser->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => now(),
            'last_login_ip'      => $request->ip(),
        ]);

        $sessionToken = $matchedUser->createToken('mobile-biometric')->plainTextToken;

        $this->logActivity($matchedUser, 'LOGIN', [
            'method' => 'biometric',
        ], $request);

        return response()->json([
            'message' => 'Biometric login successful.',
            'user'    => $this->formatUser($matchedUser),
            'token'   => $sessionToken,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function resolveBarangayFromRequest(array $validated): ?object
    {
        if (!empty($validated['barangay_id'])) {
            return DB::table('barangays')
                ->where('barangay_id', (int) $validated['barangay_id'])
                ->first();
        }

        $barangayName = trim((string) ($validated['barangay'] ?? ''));

        if ($barangayName === '') {
            return null;
        }

        return DB::table('barangays')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($barangayName)])
            ->first();
    }

    private function getResidentProfilePayload(User $user): array
    {
        $profile = DB::table('resident_profiles as rp')
            ->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
            ->select(
                'rp.id',
                'rp.barangay_id',
                'b.name as barangay',
                'rp.birth_date',
                'rp.birthdate',
                'rp.date_of_birth',
                'rp.sex'
            )
            ->where('rp.user_id', $user->user_id)
            ->first();

        if (!$profile) {
            return [
                'barangay_id' => null,
                'barangay' => $user->barangay,
                'birthday' => $this->parseBirthday($user->birthday),
                'sex' => $user->sex,
            ];
        }

        return [
            'barangay_id' => $profile->barangay_id ? (int) $profile->barangay_id : null,
            'barangay' => $profile->barangay ?: $user->barangay,
            'birthday' => $this->parseBirthday(
                $profile->birth_date
                    ?? $profile->birthdate
                    ?? $profile->date_of_birth
                    ?? $user->birthday
            ),
            'sex' => $profile->sex ?: $user->sex,
        ];
    }

    private function formatUser(User $user): array
    {
        $profile = $this->getResidentProfilePayload($user);

        return [
            'user_id'           => $user->user_id,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'email'             => $user->email,
            'mobile_number'     => $user->mobile_number,

            // Important for mobile + heatmap chatbot context.
            'barangay_id'       => $profile['barangay_id'],
            'barangay'          => $profile['barangay'],

            'birthday'          => $profile['birthday'],
            'sex'               => $profile['sex'],
            'account_status'    => $user->account_status,
            'role'              => $user->role?->name,
            'id_verified'       => (bool) $user->id_verified,
            'biometric_enabled' => (bool) $user->biometric_enabled,
            'avatar'            => $user->profile_picture_url ?? $user->avatar,
            'profile_picture'   => $user->profile_picture,
        ];
    }

    private function formatAdminUser(User $user, string $roleName): array
    {
        $capabilities = self::ROLE_CAPABILITIES[$roleName] ?? [];

        return array_merge($this->formatUser($user), [
            'capabilities'      => $capabilities,
            'role_permissions'  => $capabilities,
        ]);
    }

    private function parseBirthday(mixed $birthday): ?string
    {
        if ($birthday === null || $birthday === '') {
            return null;
        }

        if ($birthday instanceof \DateTimeInterface) {
            return Carbon::instance($birthday)->toDateString();
        }

        try {
            return Carbon::parse((string) $birthday)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function logActivity(User $user, string $action, array $meta, Request $request): void
    {
        try {
            ActivityLog::create([
                'user_id'    => $user->user_id,
                'action'     => $action,
                'module'     => 'auth',
                'metadata'   => array_merge($meta, [
                    'ip' => $request->ip(),
                ]),
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable) {
            // Do not break auth flow if activity logging fails.
        }
    }
}