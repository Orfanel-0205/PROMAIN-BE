<?php
// app/Http/Controllers/Api/AuthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\BiometricAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /*
     * Web admin portal roles.
     * FIX: staff, staff_admin, admin, it_staff, municipal_mayor, and superadmin are included.
     */
    private const ADMIN_ROLES = [
        'doctor',
        'nurse',
        'midwife',
        'bhw',
        'staff',
        'staff_admin',
        'admin',
        'rhu_admin',
        'mho',
        'mho_admin',
        'municipal_mayor',
        'it_staff',
        'super_admin',
        'superadmin',
    ];

    private const ROLE_CAPABILITIES = [
        'super_admin' => [
            'full_access',
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
            'audit',
        ],
        'superadmin' => [
            'full_access',
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
            'audit',
        ],
        'it_staff' => [
            'full_access',
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
            'audit',
        ],
        'municipal_mayor' => [
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'reports',
            'audit',
        ],
        'mho' => [
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
            'audit',
        ],
        'mho_admin' => [
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
            'audit',
        ],
        'rhu_admin' => [
            'full_access',
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
            'audit',
        ],
        'admin' => [
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
        ],
        'staff_admin' => [
            'cms',
            'analytics',
            'queue',
            'telemedicine',
            'user_management',
            'inventory',
            'reports',
        ],
        'doctor' => [
            'telemedicine',
            'queue',
            'consultations',
            'prescriptions',
        ],
        'nurse' => [
            'queue',
            'telemedicine',
            'consultations',
            'inventory',
        ],
        'midwife' => [
            'queue',
            'appointments',
            'consultations',
        ],
        'bhw' => [
            'queue',
            'appointments',
            'reports',
        ],
        'staff' => [
            'queue',
            'appointments',
            'consultations',
            'telemedicine',
            'cms',
        ],
    ];

    // =========================================================================
    // ADMIN WEB LOGIN  POST /api/v1/admin/login
    // =========================================================================

    public function adminLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required_without:email', 'nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required_without:mobile_number', 'nullable', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) (
            $validated['mobile_number']
            ?? $validated['phone']
            ?? $validated['email']
            ?? ''
        ));

        $password = (string) $validated['password'];

        $normalizedMobile = $this->normalizeMobileNumber($login);
        $normalizedEmail = strtolower($login);

        $rateLimitKey = 'admin_login|' . $login . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::with('role')
            ->where(function ($query) use ($login, $normalizedMobile, $normalizedEmail) {
                if ($normalizedMobile !== '') {
                    $query->where('mobile_number', $normalizedMobile);

                    if (Schema::hasColumn('users', 'phone')) {
                        $query->orWhere('phone', $normalizedMobile);
                    }
                }

                if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
                    $query->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]);
                }

                $query->orWhere('mobile_number', $login)
                    ->orWhere('email', $login);
            })
            ->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'Account temporarily locked. Try again after '
                    . $user->locked_until->diffForHumans() . '.',
            ], 423);
        }

        if (!$user || !Hash::check($password, (string) $user->password)) {
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
                'message' => 'Invalid mobile number or password.',
            ], 401);
        }

        $roleName = $this->normalizeRoleName($this->resolveUserRoleName($user));

        if (!in_array($roleName, self::ADMIN_ROLES, true)) {
            return response()->json([
                'message' => 'Access denied. This portal is for RHU staff only.',
                'role' => $roleName,
            ], 403);
        }

        $status = $this->normalizeStatus((string) ($user->account_status ?? ''));

        if ($status !== 'active') {
            return response()->json([
                'message' => match ($status) {
                    'pending' => 'Your account is pending approval.',
                    'suspended' => 'Your account has been suspended.',
                    'rejected' => 'Your registration was not approved.',
                    'inactive' => 'Your account is inactive.',
                    default => 'Account is not active.',
                },
            ], 403);
        }

        /*
         * FIX:
         * If the account is active and belongs to RHU staff/admin portal,
         * make sure staff verification fields are clean.
         */
        $approvalUpdates = [];

        if (Schema::hasColumn('users', 'id_verified') && !$user->id_verified) {
            $approvalUpdates['id_verified'] = true;
        }

        if (Schema::hasColumn('users', 'staff_approved_at') && !$user->staff_approved_at) {
            $approvalUpdates['staff_approved_at'] = now();
        }

        if (Schema::hasColumn('users', 'rejection_reason')) {
            $approvalUpdates['rejection_reason'] = null;
        }

        if (!empty($approvalUpdates)) {
            $user->forceFill($approvalUpdates)->save();
            $user->refresh();
            $user->loadMissing('role');
        }

        RateLimiter::clear($rateLimitKey);

        $loginUpdates = [];

        if (Schema::hasColumn('users', 'failed_login_count')) {
            $loginUpdates['failed_login_count'] = 0;
        }

        if (Schema::hasColumn('users', 'locked_until')) {
            $loginUpdates['locked_until'] = null;
        }

        if (Schema::hasColumn('users', 'last_login_at')) {
            $loginUpdates['last_login_at'] = now();
        }

        if (Schema::hasColumn('users', 'last_login_ip')) {
            $loginUpdates['last_login_ip'] = $request->ip();
        }

        if (!empty($loginUpdates)) {
            $user->forceFill($loginUpdates)->save();
        }

        $token = $user->createToken('web-admin')->plainTextToken;

        $this->logActivity($user, 'ADMIN_LOGIN', [
            'method' => 'mobile_password',
            'role' => $roleName,
        ], $request);

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->formatAdminUser($user->fresh()->load('role'), $roleName),
            'token' => $token,
        ]);
    }

    // =========================================================================
    // MOBILE LOGIN  POST /api/v1/login
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $mobile = $this->normalizeMobileNumber($validated['mobile_number']);
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
            $reason = trim((string) $user->rejection_reason);

            return response()->json([
                'message' => match ($user->account_status) {
                    'pending' => 'Your account is pending Super Admin approval.',
                    'suspended' => 'Your account has been suspended. Contact the RHU.',
                    'rejected' => $reason !== ''
                        ? 'Your account was rejected. Reason: ' . $reason
                        : 'Your registration was not approved.',
                    default => 'Account is not active.',
                },
                'account_status' => $user->account_status,
                'rejection_reason' => $user->account_status === 'rejected' ? ($reason ?: null) : null,
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);

        $user->update([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        $this->logActivity($user, 'LOGIN', [
            'method' => 'mobile_password',
        ], $request);

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->formatUser($user->fresh()->load('role')),
            'token' => $token,
        ]);
    }

    // =========================================================================
    // REGISTER  POST /api/v1/register
    // =========================================================================

    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'barangay' => trim((string) $request->input('barangay', '')),
            'mobile_number' => $this->normalizeMobileNumber($request->input('mobile_number')),
        ]);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            // Ignore archived (soft-deleted) accounts — a released number/email
            // must be reusable (Part 6 archive-not-delete premise).
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->whereNull('deleted_at'), 'max:255'],
            'mobile_number' => ['required', 'string', 'regex:/^09\d{9}$/', Rule::unique('users', 'mobile_number')->whereNull('deleted_at')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required'],

            'barangay' => [
                'required_without:barangay_id',
                'nullable',
                'string',
                'max:150',
                Rule::exists('barangays', 'name'),
            ],

            'barangay_id' => ['nullable', 'integer', Rule::exists('barangays', 'barangay_id')],

            'birthday' => [
                'nullable',
                'date',
                'before:' . now()->subYears(18)->toDateString(),
                'after:1900-01-01',
            ],
            'birth_date' => [
                'nullable',
                'date',
                'before:' . now()->subYears(18)->toDateString(),
                'after:1900-01-01',
            ],
            'sex' => ['nullable', Rule::in(['male', 'female', 'other'])],

            // Residents MUST accept the Terms and Conditions to register.
            'terms_accepted' => ['required', 'accepted'],
        ], [
            'terms_accepted.required' => 'You must accept the Terms and Conditions to register.',
            'terms_accepted.accepted' => 'You must accept the Terms and Conditions to register.',
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

        $residentRole = $this->resolveRoleModel('resident');

        if (!$residentRole) {
            return response()->json([
                'message' => 'Resident role was not found.',
            ], 422);
        }

        $user = DB::transaction(function () use ($validated, $residentRole, $barangay): User {
            $birthday = $validated['birthday'] ?? $validated['birth_date'] ?? null;

            $user = User::create([
                'role_id' => $residentRole->role_id,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'] ?? null,
                'mobile_number' => $validated['mobile_number'],
                'password' => Hash::make($validated['password']),

                'barangay' => $barangay->name,

                'birthday' => $birthday,
                'sex' => $validated['sex'] ?? null,

                /*
                 * FINAL REGISTRATION RULE:
                 * ALL registrants stay PENDING until a Super Admin approves them.
                 * The resident must accept the Terms and submit a valid ID (OCR)
                 * for review. OCR never auto-approves the account — only Super
                 * Admin approval flips account_status to 'active'.
                 */
                'account_status' => 'pending',
                'id_verified' => false,
                'biometric_enabled' => false,
                'terms_accepted_at' => now(),
            ]);

            $profilePayload = [
                'barangay_id' => (int) $barangay->barangay_id,
            ];

            if (Schema::hasColumn('resident_profiles', 'first_name')) {
                $profilePayload['first_name'] = $validated['first_name'];
            }

            if (Schema::hasColumn('resident_profiles', 'last_name')) {
                $profilePayload['last_name'] = $validated['last_name'];
            }

            if (Schema::hasColumn('resident_profiles', 'mobile_number')) {
                $profilePayload['mobile_number'] = $validated['mobile_number'];
            }

            if (Schema::hasColumn('resident_profiles', 'birth_date')) {
                $profilePayload['birth_date'] = $birthday;
            }

            if (Schema::hasColumn('resident_profiles', 'birthdate')) {
                $profilePayload['birthdate'] = $birthday;
            }

            if (Schema::hasColumn('resident_profiles', 'date_of_birth')) {
                $profilePayload['date_of_birth'] = $birthday;
            }

            if (Schema::hasColumn('resident_profiles', 'sex')) {
                $profilePayload['sex'] = $validated['sex'] ?? null;
            }

            ResidentProfile::updateOrCreate(
                ['user_id' => $user->user_id],
                $profilePayload
            );

            return $user->fresh()->load('role');
        });

        $token = $user->createToken('mobile')->plainTextToken;

        $this->logActivity($user, 'REGISTER', [
            'barangay' => $barangay->name,
            'barangay_id' => (int) $barangay->barangay_id,
        ], $request);

        // Resident self-registration lands in 'pending' — tell them so by SMS,
        // reusing the same account-lifecycle sender the staff registration path
        // already uses (dispatch() is fire-and-forget: it logs to sms_logs and
        // never throws into the registration response).
        app(\App\Services\Notification\AccountSmsService::class)
            ->sendRegistrationPending($user);

        // Alert the Super Admin(s) that a registration is waiting for review —
        // in-app notification row only, fired after the creation transaction
        // committed; internally try/caught so it can never block or fail this
        // response.
        app(\App\Services\Notification\RegistrationReviewNotifier::class)
            ->newResidentRegistration($user);

        return response()->json([
            'message' => 'Registration submitted successfully. Please complete ID verification. Your account will remain pending until reviewed by the Super Admin.',
            'user' => $this->formatUser($user),
            'token' => $token,
            // ID verification (OCR) is REQUIRED before Super Admin approval.
            'next_step' => 'upload_id',
            'requires_id_upload' => true,
            'account_status' => 'pending',
        ], 201);
    }

    // =========================================================================
    // OTP / PASSWORD ROUTES
    // =========================================================================

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'otp_code' => ['required', 'string', 'max:20'],
        ]);

        $user = $this->findUserByLogin(
            $validated['mobile_number'] ?? $validated['email'] ?? ''
        );

        if (!$user) {
            return response()->json([
                'message' => 'Account not found.',
            ], 404);
        }

        if ((string) $user->otp_code !== (string) $validated['otp_code']) {
            return response()->json([
                'message' => 'Invalid OTP code.',
            ], 422);
        }

        if ($user->otp_expires_at && $user->otp_expires_at->isPast()) {
            return response()->json([
                'message' => 'OTP code has expired.',
            ], 422);
        }

        $user->update([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'OTP verified successfully.',
            'user' => $this->formatUser($user->fresh()->load('role')),
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        $user = $this->findUserByLogin(
            $validated['mobile_number'] ?? $validated['email'] ?? ''
        );

        if (!$user) {
            return response()->json([
                'message' => 'Account not found.',
            ], 404);
        }

        $otp = (string) random_int(100000, 999999);

        $user->update([
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        return response()->json([
            'message' => 'OTP sent successfully.',
            'otp_expires_at' => optional($user->fresh()->otp_expires_at)->toISOString(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        $user = $this->findUserByLogin(
            $validated['mobile_number'] ?? $validated['email'] ?? ''
        );

        if (!$user) {
            return response()->json([
                'message' => 'Account not found.',
            ], 404);
        }

        $otp = (string) random_int(100000, 999999);

        $user->update([
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        return response()->json([
            'message' => 'Password reset OTP sent successfully.',
            'otp_expires_at' => optional($user->fresh()->otp_expires_at)->toISOString(),
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'otp_code' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required'],
        ]);

        $user = $this->findUserByLogin(
            $validated['mobile_number'] ?? $validated['email'] ?? ''
        );

        if (!$user) {
            return response()->json([
                'message' => 'Account not found.',
            ], 404);
        }

        if ((string) $user->otp_code !== (string) $validated['otp_code']) {
            return response()->json([
                'message' => 'Invalid OTP code.',
            ], 422);
        }

        if ($user->otp_expires_at && $user->otp_expires_at->isPast()) {
            return response()->json([
                'message' => 'OTP code has expired.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'otp_code' => null,
            'otp_expires_at' => null,
            'failed_login_count' => 0,
            'locked_until' => null,
        ]);

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    // =========================================================================
    // SHARED AUTH ROUTES
    // =========================================================================

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        $this->logActivity($request->user(), 'LOGOUT', [], $request);

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');
        $role = $this->normalizeRoleName($this->resolveUserRoleName($user));

        if (in_array($role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'user' => $this->formatAdminUser($user, $role),
            ]);
        }

        return response()->json([
            'user' => $this->formatUser($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'birthday' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'sex' => ['nullable', Rule::in(['male', 'female', 'other'])],
        ]);

        $updates = [];

        foreach (['first_name', 'last_name', 'email', 'barangay', 'sex'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if (array_key_exists('mobile_number', $validated)) {
            $mobile = $this->normalizeMobileNumber($validated['mobile_number']);
            abort_unless($mobile === '' || preg_match('/^09\d{9}$/', $mobile), 422, 'Mobile number must use this format: 09XXXXXXXXX.');
            $updates['mobile_number'] = $mobile;
        }

        if (array_key_exists('birthday', $validated) || array_key_exists('birth_date', $validated)) {
            $updates['birthday'] = $validated['birthday'] ?? $validated['birth_date'] ?? null;
        }

        if (!empty($updates)) {
            $user->update($updates);
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->formatUser($user->fresh()->load('role')),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required'],
        ]);

        $user = $request->user();

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

    // =========================================================================
    // BIOMETRIC LOGIN  POST /api/v1/biometric/login
    // =========================================================================

    public function biometricLogin(Request $request, BiometricAuthService $biometrics): JsonResponse
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

        $matchedUser = null;

        if (Schema::hasTable('biometric_tokens')) {
            try {
                $matchedUser = $biometrics->validate($rawToken);
            } catch (\Throwable) {
                $matchedUser = null;
            }
        }

        if (!$matchedUser && Schema::hasColumn('users', 'biometric_token_hash')) {
            $users = User::query()
                ->with('role')
                ->where('biometric_enabled', true)
                ->whereNotNull('biometric_token_hash')
                ->get();

            foreach ($users as $user) {
                if (Hash::check($rawToken, $user->biometric_token_hash)) {
                    $matchedUser = $user;
                    break;
                }
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

        $matchedUser->loadMissing('role');

        $matchedUser->update([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $sessionToken = $matchedUser->createToken('mobile-biometric')->plainTextToken;

        $this->logActivity($matchedUser, 'LOGIN', [
            'method' => 'biometric',
        ], $request);

        return response()->json([
            'message' => 'Biometric login successful.',
            'user' => $this->formatUser($matchedUser->fresh()->load('role')),
            'token' => $sessionToken,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

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

    private function normalizeRoleName(?string $role): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $role)));
    }

    /**
     * Resident-type roles get the simple, active-on-registration flow.
     * Accepts a role name string or a User instance.
     */
    private function isResidentRole(User|string|null $userOrRole): bool
    {
        $role = $userOrRole instanceof User
            ? $this->resolveUserRoleName($userOrRole)
            : (string) $userOrRole;

        return in_array($this->normalizeRoleName($role), ['resident', 'patient'], true);
    }

    /**
     * Staff/admin/personnel roles require Super Admin approval (pending until
     * reviewed). Everything that is not a resident role requires approval.
     */
    private function requiresApproval(User|string|null $userOrRole): bool
    {
        return !$this->isResidentRole($userOrRole);
    }

    private function normalizeStatus(?string $status): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim((string) $status)));
    }

    private function resolveUserRoleName(User $user): string
    {
        $user->loadMissing('role');

        if (is_string($user->role ?? null)) {
            return (string) $user->role;
        }

        if (is_object($user->role ?? null)) {
            foreach (['name', 'role_name', 'slug', 'role', 'title', 'code'] as $field) {
                if (!empty($user->role->{$field})) {
                    return (string) $user->role->{$field};
                }
            }
        }

        return (string) (
            $user->role_name
            ?? $user->account_type
            ?? 'resident'
        );
    }

    private function resolveRoleModel(string $roleName): ?UserRole
    {
        $normalized = $this->normalizeRoleName($roleName);

        $roles = UserRole::query()->get();

        foreach ($roles as $role) {
            foreach (['name', 'role_name', 'slug', 'role', 'title', 'code'] as $field) {
                if (
                    isset($role->{$field}) &&
                    $this->normalizeRoleName((string) $role->{$field}) === $normalized
                ) {
                    return $role;
                }
            }
        }

        return null;
    }

    private function findUserByLogin(?string $login): ?User
    {
        $login = trim((string) $login);
        $mobile = $this->normalizeMobileNumber($login);
        $email = strtolower($login);

        return User::with('role')
            ->where(function ($query) use ($login, $mobile, $email) {
                if ($mobile !== '') {
                    $query->where('mobile_number', $mobile);
                }

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                $query->orWhere('mobile_number', $login)
                    ->orWhere('email', $login);
            })
            ->first();
    }

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
        $selects = [
            'rp.id',
            'rp.barangay_id',
            'b.name as barangay',
        ];

        $birthColumns = [
            'birth_date',
            'birthdate',
            'date_of_birth',
        ];

        foreach ($birthColumns as $column) {
            if (Schema::hasColumn('resident_profiles', $column)) {
                $selects[] = "rp.$column";
            }
        }

        if (Schema::hasColumn('resident_profiles', 'sex')) {
            $selects[] = 'rp.sex';
        }

        $profile = DB::table('resident_profiles as rp')
            ->leftJoin('barangays as b', 'b.barangay_id', '=', 'rp.barangay_id')
            ->where('rp.user_id', $user->user_id)
            ->select($selects)
            ->first();

        if (!$profile) {
            return [
                'barangay_id' => null,
                'barangay' => $user->barangay,
                'birthday' => $this->parseBirthday($user->birthday),
                'sex' => $user->sex,
            ];
        }

        $birthday = $user->birthday;

        foreach ($birthColumns as $column) {
            if (property_exists($profile, $column) && !empty($profile->{$column})) {
                $birthday = $profile->{$column};
                break;
            }
        }

        $sex = property_exists($profile, 'sex') && !empty($profile->sex)
            ? $profile->sex
            : $user->sex;

        return [
            'barangay_id' => $profile->barangay_id ? (int) $profile->barangay_id : null,
            'barangay' => $profile->barangay ?: $user->barangay,
            'birthday' => $this->parseBirthday($birthday),
            'sex' => $sex,
        ];
    }

    private function formatUser(User $user): array
    {
        $user->loadMissing('role');

        $profile = $this->getResidentProfilePayload($user);
        $roleName = $this->normalizeRoleName($this->resolveUserRoleName($user));

        return [
            'id' => $user->user_id,
            'user_id' => $user->user_id,

            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => trim((string) $user->first_name . ' ' . (string) $user->last_name),
            'full_name' => trim((string) $user->first_name . ' ' . (string) $user->last_name),

            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'phone' => $user->mobile_number,

            'barangay_id' => $profile['barangay_id'],
            'barangay' => $profile['barangay'],

            'birthday' => $profile['birthday'],
            'sex' => $profile['sex'],

            'account_status' => $user->account_status,
            'status' => $user->account_status,

            'role' => $roleName,
            'role_name' => $roleName,
            'role_id' => $user->role_id,

            'id_verified' => (bool) $user->id_verified,
            'staff_approved_by' => $user->staff_approved_by,
            'staff_approved_at' => optional($user->staff_approved_at)->toISOString(),

            'biometric_enabled' => (bool) $user->biometric_enabled,
            'avatar' => $user->profile_picture_url ?? $user->avatar,
            'profile_picture' => $user->profile_picture,

            'capabilities' => $this->capabilitiesForRole($roleName),
        ];
    }

    private function formatAdminUser(User $user, string $roleName): array
    {
        $roleName = $this->normalizeRoleName($roleName);

        return array_merge($this->formatUser($user), [
            'role' => $roleName,
            'role_name' => $roleName,
            'capabilities' => $this->capabilitiesForRole($roleName),
            'role_permissions' => $this->capabilitiesForRole($roleName),
        ]);
    }

    private function capabilitiesForRole(string $roleName): array
    {
        $roleName = $this->normalizeRoleName($roleName);

        return self::ROLE_CAPABILITIES[$roleName] ?? [];
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
                'user_id' => $user->user_id,
                'user_role' => $this->normalizeRoleName($this->resolveUserRoleName($user)),
                'action' => $action,
                'module' => 'auth',
                'severity' => 'info',
                'subject_type' => User::class,
                'subject_id' => $user->user_id,
                'subject_label' => trim((string) $user->first_name . ' ' . (string) $user->last_name),
                'metadata' => array_merge($meta, [
                    'ip' => $request->ip(),
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'device_type' => $this->detectDeviceType($request->userAgent()),
                'http_method' => $request->method(),
                'route_name' => optional($request->route())->getName() ?? $request->path(),
            ]);
        } catch (\Throwable) {
            // Do not break auth flow if activity logging fails.
        }
    }

    private function detectDeviceType(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);

        if (str_contains($agent, 'mobile') || str_contains($agent, 'android') || str_contains($agent, 'iphone')) {
            return 'mobile';
        }

        if (str_contains($agent, 'tablet') || str_contains($agent, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }
}
