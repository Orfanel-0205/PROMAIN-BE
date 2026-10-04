<?php
// app/Http/Controllers/Api/AuthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\BiometricAuthService;
use App\Services\Auth\VerificationCodes;
use App\Services\Notification\AccountMailService;
use App\Services\Notification\AccountSmsService;
use App\Services\PasswordPolicyService;
use App\Support\AppSettings;
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
    /** Every forgot-password reply takes at least this long. See answerNoSoonerThan(). */
    private const RESET_REPLY_SECONDS = 3.0;

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

        // Per-account attempt ceiling, supplied by the admin Settings page
        // (Security Rules -> Max Login Attempts). See AppSettings for why the
        // accessor clamps and falls back to 5 rather than trusting the row.
        if (RateLimiter::tooManyAttempts($rateLimitKey, AppSettings::maxLoginAttempts())) {
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

        // A wrong password since the last sign-in: the right one is not
        // enough on its own. See stepUpIfNeeded().
        $step = $this->stepUpIfNeeded($user, 'admin', VerificationCodes::PURPOSE_ADMIN_LOGIN, $request);

        if ($step !== null) {
            return $step;
        }

        return $this->completeAdminLogin($user, $roleName, $request, $rateLimitKey, 'mobile_password');
    }

    /**
     * Everything that happens once a staff sign-in is proven -- by the
     * password alone, or by the password and then an SMS code.
     */
    private function completeAdminLogin(User $user, string $roleName, Request $request, ?string $rateLimitKey, string $method): JsonResponse
    {
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

        if ($rateLimitKey !== null) {
            RateLimiter::clear($rateLimitKey);
        }

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
            'method' => $method,
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

        // Per-account attempt ceiling, supplied by the admin Settings page
        // (Security Rules -> Max Login Attempts). See AppSettings for why the
        // accessor clamps and falls back to 5 rather than trusting the row.
        if (RateLimiter::tooManyAttempts($rateLimitKey, AppSettings::maxLoginAttempts())) {
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

        // A wrong password since the last sign-in: the right one is not
        // enough on its own. See stepUpIfNeeded().
        $step = $this->stepUpIfNeeded($user, 'resident', VerificationCodes::PURPOSE_RESIDENT_LOGIN, $request);

        if ($step !== null) {
            return $step;
        }

        return $this->completeResidentLogin($user, $request, $rateLimitKey, 'mobile_password');
    }

    /**
     * Everything that happens once a resident sign-in is proven -- by the
     * password alone, or by the password and then an SMS code.
     */
    private function completeResidentLogin(User $user, Request $request, ?string $rateLimitKey, string $method): JsonResponse
    {
        if ($rateLimitKey !== null) {
            RateLimiter::clear($rateLimitKey);
        }

        $user->update([
            'failed_login_count' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        $this->logActivity($user, 'LOGIN', [
            'method' => $method,
        ], $request);

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->formatUser($user->fresh()->load('role')),
            'token' => $token,
        ]);
    }

    // =========================================================================
    // SIGN-IN CODES, after a wrong password
    //   POST /api/v1/admin/login/verify-code   { challenge, code }
    //   POST /api/v1/admin/login/resend-code   { challenge }
    //   POST /api/v1/login/verify-code         { challenge, code }
    //   POST /api/v1/login/resend-code         { challenge }
    // =========================================================================

    /**
     * Text a code instead of signing in, when this account has had a wrong
     * password since its last successful sign-in.
     *
     * failed_login_count already rises on every wrong password and returns to
     * zero on a successful sign-in, so it is exactly "has a wrong password been
     * entered since". It is reset only once the sign-in is complete -- after
     * the code, when one was required -- so someone who has the password but
     * not the phone cannot clear it by simply trying again.
     *
     * The code is sent only here, after the password has been checked. A
     * stranger guessing passwords therefore never causes a text to be sent.
     *
     * Returns null to carry on signing in normally.
     *
     * WHEN NO CODE CAN BE SENT
     *   No valid mobile number on the account: sign in as before, and record
     *   it. Refusing would lock the account permanently over a number only
     *   the account holder could have supplied.
     *   The text could not be sent -- no SMS credit, Semaphore down: refuse.
     *   A wrong password has been entered and the second check cannot be
     *   made, so the safe answer is to wait, or to have RHU staff reset the
     *   password, which clears the check.
     *
     * The response is 403, never 200: the mobile app's current build treats
     * any 200 from /login as a finished sign-in and stores whatever token it
     * contains. As a 403 it shows this message instead -- which is why the
     * message says to update the app if there is nowhere to type the code.
     */
    private function stepUpIfNeeded(User $user, string $audience, string $purpose, Request $request): ?JsonResponse
    {
        if (!config("auth.login_codes.{$audience}", true)) {
            return null;
        }

        if ((int) ($user->failed_login_count ?? 0) < 1 || !Schema::hasTable('verification_codes')) {
            return null;
        }

        $issued = app(VerificationCodes::class)->issue($user, $purpose, [], $request);

        switch ($issued['status']) {
            case 'sent':
                $this->logActivity($user, 'LOGIN_CODE_SENT', ['audience' => $audience], $request);

                return response()->json([
                    'message' => "For your security, enter the 6-digit code we sent to your mobile number ending in {$issued['masked_mobile']}. "
                        . 'A wrong password was entered for this account since its last sign-in. '
                        . "If you don't see where to enter it, update the Ka-Agapay app.",
                    'code_required' => true,
                    'challenge' => $issued['challenge'],
                    'masked_mobile' => $issued['masked_mobile'],
                    'expires_in' => $issued['expires_in'],
                    'resend_after' => $issued['resend_after'],
                ], 403);

            case 'no_mobile':
                $this->logActivity($user, 'LOGIN_CODE_SKIPPED', ['audience' => $audience, 'reason' => 'no_mobile'], $request);

                return null;

            case 'too_many':
                return response()->json([
                    'message' => 'Too many sign-in codes have been sent to this account. Try again later, or ask RHU staff to reset your password.',
                ], 429);

            default:
                $this->logActivity($user, 'LOGIN_CODE_SKIPPED', ['audience' => $audience, 'reason' => 'send_failed'], $request);

                return response()->json([
                    'message' => "We couldn't send your sign-in code right now. Try again in a few minutes, or ask RHU staff to reset your password.",
                ], 503);
        }
    }

    public function verifyAdminLoginCode(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->finishWithCode($request, $codes, VerificationCodes::PURPOSE_ADMIN_LOGIN);
    }

    public function verifyLoginCode(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->finishWithCode($request, $codes, VerificationCodes::PURPOSE_RESIDENT_LOGIN);
    }

    public function resendAdminLoginCode(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->resendCode($request, $codes, VerificationCodes::PURPOSE_ADMIN_LOGIN);
    }

    public function resendLoginCode(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->resendCode($request, $codes, VerificationCodes::PURPOSE_RESIDENT_LOGIN);
    }

    /**
     * Second half of a sign-in that needed a code.
     *
     * A wrong code answers 422 and an expired one 410 -- never 401, which the
     * admin treats as an expired session and answers by reloading the page,
     * which would throw away the code screen mid-entry.
     */
    private function finishWithCode(Request $request, VerificationCodes $codes, string $purpose): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $result = $codes->verify($validated['challenge'], $purpose, $validated['code']);

        if ($result['status'] === 'wrong') {
            $left = (int) $result['attempts_left'];

            if (($result['user'] ?? null) instanceof User) {
                // Right password, wrong code: someone may know the password
                // without having the phone. Worth a line in the record.
                $this->logActivity($result['user'], 'LOGIN_CODE_FAILED', ['attempts_left' => $left], $request);
            }

            return response()->json([
                'message' => $left > 0
                    ? "Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.'
                    : 'Incorrect code. Sign in again to get a new one.',
                'attempts_left' => $left,
                'restart' => $left === 0,
            ], $left > 0 ? 422 : 410);
        }

        if ($result['status'] !== 'ok' || !(($result['user'] ?? null) instanceof User)) {
            return response()->json([
                'message' => 'This code has expired. Sign in again to get a new one.',
                'restart' => true,
            ], 410);
        }

        $user = $result['user']->loadMissing('role');

        // What may have changed in the minutes since the password was checked.
        if ($user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'Account locked. Try again after ' . $user->locked_until->diffForHumans() . '.',
                'restart' => true,
            ], 423);
        }

        if ($purpose === VerificationCodes::PURPOSE_ADMIN_LOGIN) {
            $roleName = $this->normalizeRoleName($this->resolveUserRoleName($user));

            if (!in_array($roleName, self::ADMIN_ROLES, true)
                || $this->normalizeStatus((string) ($user->account_status ?? '')) !== 'active') {
                return response()->json([
                    'message' => 'This account can no longer sign in to the RHU portal.',
                    'restart' => true,
                ], 403);
            }

            return $this->completeAdminLogin($user, $roleName, $request, null, 'mobile_password+sms_code');
        }

        if ($user->account_status !== 'active') {
            return response()->json(['message' => 'Account is not active.', 'restart' => true], 403);
        }

        return $this->completeResidentLogin($user, $request, null, 'mobile_password+sms_code');
    }

    private function resendCode(Request $request, VerificationCodes $codes, string $purpose): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
        ]);

        $result = $codes->resend($validated['challenge'], $purpose);

        return match ($result['status']) {
            'sent' => response()->json([
                'message' => "We sent a new code to the number ending in {$result['masked_mobile']}.",
                'masked_mobile' => $result['masked_mobile'],
                'expires_in' => $result['expires_in'],
                'resend_after' => VerificationCodes::RESEND_AFTER_SECONDS,
            ]),
            'wait' => response()->json([
                'message' => "Wait {$result['wait']} seconds before asking for another code.",
                'wait' => $result['wait'],
            ], 429),
            'limit' => response()->json([
                'message' => 'No more codes can be sent for this sign-in. Sign in again later, or ask RHU staff to reset your password.',
                'restart' => true,
            ], 429),
            'send_failed' => response()->json([
                'message' => "We couldn't send a new code right now. Try again in a few minutes.",
            ], 503),
            default => response()->json([
                'message' => 'This sign-in has expired. Sign in again to get a new code.',
                'restart' => true,
            ], 410),
        };
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

    /*
     * resend-otp belonged to an OTP flow that was never finished: the otp_code
     * column it used does not exist in production, and no client calls it. It
     * stays closed. Forgot password, below, replaced the rest of that flow.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'This step is no longer used. To reset a forgotten password, use "Forgot password?" on the sign-in screen.',
        ], 410);
    }

    // =========================================================================
    // FORGOT PASSWORD
    //   POST /api/v1/forgot-password               { login }      residents
    //   POST /api/v1/forgot-password/resend        { challenge }
    //   POST /api/v1/reset-password                { challenge, code, password, password_confirmation }
    //   POST /api/v1/admin/forgot-password         { login }      RHU staff
    //   POST /api/v1/admin/forgot-password/resend  { challenge }
    //   POST /api/v1/admin/reset-password          { challenge, code, password, password_confirmation }
    // =========================================================================
    //
    // The person types their mobile number or email. A six-digit code goes to
    // the account's mobile number, and to its email when it has a real one;
    // the code and a new password together set the password.
    //
    // NOTHING HERE SAYS WHETHER AN ACCOUNT EXISTS
    // An earlier version answered an unknown number with "Account not found",
    // which let anyone check whether a given number belongs to a patient of
    // the RHU. Now every request gets the same answer and a challenge. When no
    // account matches -- or it is the wrong kind, inactive, over its code
    // limit, or the text could not be sent -- the challenge is a stand-in
    // (VerificationCodes::issueDecoy) that behaves like a real one in every
    // later step. The reason is written to the activity log, not the reply.
    //
    // A staff number on the resident app, or a resident's on the admin page,
    // is treated as no match: each sign-in screen resets only its own accounts.
    //
    // AFTER A RESET
    // Every signed-in device is signed out, pending codes are retired, the
    // wrong-password check is cleared, and the account holder is told by SMS
    // and email. Viewing API keys pauses for a day (password_reset_at), so a
    // super admin whose phone was taken over does not hand over the keys too.

    public function forgotPassword(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->startPasswordReset($request, $codes, 'resident');
    }

    public function adminForgotPassword(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->startPasswordReset($request, $codes, 'admin');
    }

    public function resetPassword(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->finishPasswordReset($request, $codes, 'resident');
    }

    public function adminResetPassword(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->finishPasswordReset($request, $codes, 'admin');
    }

    public function resendResetCode(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->resendPasswordResetCode($request, $codes, 'resident');
    }

    public function adminResendResetCode(Request $request, VerificationCodes $codes): JsonResponse
    {
        return $this->resendPasswordResetCode($request, $codes, 'admin');
    }

    private function startPasswordReset(Request $request, VerificationCodes $codes, string $audience): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['nullable', 'string', 'max:150'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:150'],
        ]);

        $login = trim((string) ($validated['login'] ?? $validated['mobile_number'] ?? $validated['email'] ?? ''));

        if ($login === '') {
            return response()->json([
                'message' => 'Enter the mobile number or email on your account.',
                'errors' => ['login' => ['Enter the mobile number or email on your account.']],
            ], 422);
        }

        $startedAt = microtime(true);
        $purpose = $this->resetPurpose($audience);
        $user = $this->findUserByLogin($login);
        $reason = $user === null ? 'no_account' : $this->resetRefusal($user, $audience);

        $issued = null;

        if ($reason === null) {
            $issued = $codes->issue(
                $user,
                $purpose,
                [],
                $request,
                [VerificationCodes::CHANNEL_SMS, VerificationCodes::CHANNEL_EMAIL],
            );

            if ($issued['status'] === 'sent') {
                $this->logActivity($user, 'PASSWORD_RESET_CODE_SENT', [
                    'audience' => $audience,
                    'channels' => $issued['channels'] ?? [],
                ], $request);
            } else {
                $reason = $issued['status'];
                $issued = null;
            }
        }

        if ($issued === null) {
            // Recorded against the account when there is one, so staff can see
            // why someone says the code never came.
            if ($user !== null) {
                $this->logActivity($user, 'PASSWORD_RESET_CODE_SKIPPED', [
                    'audience' => $audience,
                    'reason' => $reason,
                ], $request);
            }

            $issued = $codes->issueDecoy($purpose, $request);
        }

        $this->answerNoSoonerThan($startedAt);

        return response()->json([
            'message' => 'If an account uses that number or email, we sent it a 6-digit code: by text to its mobile number, '
                . 'and by email if it has one. It expires in 5 minutes.',
            'challenge' => $issued['challenge'],
            'expires_in' => $issued['expires_in'],
            'resend_after' => $issued['resend_after'],
        ]);
    }

    private function finishPasswordReset(Request $request, VerificationCodes $codes, string $audience): JsonResponse
    {
        // The new password is checked first, so a weak one costs no code attempt.
        $validated = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'confirmed', PasswordPolicyService::standard()],
            'password_confirmation' => ['required', 'string'],
        ]);

        $result = $codes->verify($validated['challenge'], $this->resetPurpose($audience), $validated['code']);

        if ($result['status'] === 'wrong') {
            $left = (int) $result['attempts_left'];

            if (($result['user'] ?? null) instanceof User) {
                $this->logActivity($result['user'], 'PASSWORD_RESET_CODE_FAILED', ['attempts_left' => $left], $request);
            }

            return response()->json([
                'message' => $left > 0
                    ? "Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.'
                    : 'Incorrect code. Ask for a new one to try again.',
                'attempts_left' => $left,
                'restart' => $left === 0,
            ], $left > 0 ? 422 : 410);
        }

        if ($result['status'] !== 'ok' || !(($result['user'] ?? null) instanceof User)) {
            return response()->json([
                'message' => 'This code has expired. Ask for a new one.',
                'restart' => true,
            ], 410);
        }

        /** @var User $user */
        $user = $result['user']->loadMissing('role');

        // What may have changed in the five minutes since the code was sent.
        if ($this->resetRefusal($user, $audience) !== null) {
            return response()->json([
                'message' => 'This account can no longer be reset here. Please contact your Rural Health Unit.',
                'restart' => true,
            ], 403);
        }

        $updates = [
            'password' => Hash::make($validated['password']),
            'failed_login_count' => 0,
            'locked_until' => null,
        ];

        if (Schema::hasColumn('users', 'password_reset_at')) {
            $updates['password_reset_at'] = now();
        }

        $user->forceFill($updates)->save();

        $codes->retireAll($user);

        // Whoever was signed in -- possibly the person who learned the old
        // password -- is signed out everywhere.
        $user->tokens()->delete();

        app(AccountSmsService::class)->sendPasswordResetNotice($user);
        app(AccountMailService::class)->sendPasswordResetNotice($user);

        $this->logActivity($user, 'PASSWORD_RESET', [
            'audience' => $audience,
            'method' => 'reset_code',
        ], $request);

        return response()->json([
            'message' => 'Your password has been changed. Sign in with your new password.',
        ]);
    }

    private function resendPasswordResetCode(Request $request, VerificationCodes $codes, string $audience): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
        ]);

        $startedAt = microtime(true);
        $result = $codes->resend($validated['challenge'], $this->resetPurpose($audience));

        $this->answerNoSoonerThan($startedAt);

        return match ($result['status']) {
            // A failed send answers like a sent one: stand-ins never fail, so
            // anything else would reveal that this challenge is real.
            'sent', 'send_failed' => response()->json([
                'message' => 'We sent a new code. Only the newest code works.',
                'expires_in' => VerificationCodes::TTL_SECONDS,
                'resend_after' => VerificationCodes::RESEND_AFTER_SECONDS,
            ]),
            'wait' => response()->json([
                'message' => "Wait {$result['wait']} seconds before asking for another code.",
                'wait' => $result['wait'],
            ], 429),
            'limit' => response()->json([
                'message' => 'No more codes can be sent for this request. Start again later, or ask RHU staff to reset your password.',
                'restart' => true,
            ], 429),
            default => response()->json([
                'message' => 'This request has expired. Start again to get a new code.',
                'restart' => true,
            ], 410),
        };
    }

    /**
     * Hold a forgot-password reply until a fixed time has passed.
     *
     * A real request waits on Semaphore (about half a second) and, once email
     * is set up, on Gmail (a second or two); a stand-in sends nothing. Without
     * this the reply time alone would tell anyone timing it whether a number
     * has an account. Every reply -- real, stand-in, refused -- takes the same
     * three seconds or so, which is still quick for someone who forgot their
     * password.
     */
    private function answerNoSoonerThan(float $startedAt): void
    {
        $target = self::RESET_REPLY_SECONDS + random_int(0, 300) / 1000;
        $remaining = $target - (microtime(true) - $startedAt);

        if ($remaining > 0) {
            usleep((int) round($remaining * 1_000_000));
        }
    }

    private function resetPurpose(string $audience): string
    {
        return $audience === 'admin'
            ? VerificationCodes::PURPOSE_ADMIN_RESET
            : VerificationCodes::PURPOSE_RESIDENT_RESET;
    }

    /**
     * Why this account cannot be reset from this screen, or null if it can.
     *
     * Only active accounts: a pending, suspended or rejected account could not
     * sign in with a new password anyway, and a code sent to it is credit
     * spent for nothing.
     */
    private function resetRefusal(User $user, string $audience): ?string
    {
        $isStaff = in_array($this->normalizeRoleName($this->resolveUserRoleName($user)), self::ADMIN_ROLES, true);

        if ($audience === 'admin' ? !$isStaff : $isStaff) {
            return 'other_audience';
        }

        if ($this->normalizeStatus((string) ($user->account_status ?? '')) !== 'active') {
            return 'not_active';
        }

        return null;
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

        // Per-account attempt ceiling, supplied by the admin Settings page
        // (Security Rules -> Max Login Attempts). See AppSettings for why the
        // accessor clamps and falls back to 5 rather than trusting the row.
        if (RateLimiter::tooManyAttempts($rateLimitKey, AppSettings::maxLoginAttempts())) {
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

            // Drives the one-time "Getting Started" auto-open in the web admin.
            // Schema-guarded so this payload still works if the column has not
            // been migrated yet (treated as "already seen" => never auto-opens).
            'has_seen_onboarding' => Schema::hasColumn('users', 'has_seen_onboarding')
                ? (bool) $user->has_seen_onboarding
                : true,

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
