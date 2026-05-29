<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserRole;
use App\Services\BiometricAuthService;
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
    public function __construct(
        private readonly BiometricAuthService $biometricService,
    ) {}

    // =========================================================================
    // REGISTER
    // =========================================================================

    public function register(Request $request): JsonResponse
    {
        // ── 0. Normalise inputs before validation ─────────────────────────
        $request->merge([
            'barangay'      => trim((string) $request->input('barangay', '')),
            'mobile_number' => trim((string) $request->input('mobile_number', '')),
        ]);

        // ── 1. Validate ───────────────────────────────────────────────────
        $validated = $request->validate([
            'first_name'            => ['required', 'string', 'max:100'],
            'last_name'             => ['required', 'string', 'max:100'],
            'email'                 => ['nullable', 'email', 'unique:users,email', 'max:255'],
            'mobile_number'         => [
                'required', 'string',
                'regex:/^09\d{9}$/',
                'unique:users,mobile_number',
            ],
            'password'              => [
                'required', 'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required'],
            'barangay'              => [
                'required', 'string', 'max:150',
                Rule::exists('barangays', 'name'),
            ],
            'birthday' => [
                'nullable', 'date',
                'before:' . now()->subYears(18)->toDateString(),
                'after:1900-01-01',
            ],
            'sex' => ['nullable', Rule::in(['male', 'female', 'other'])],
        ], [
            'mobile_number.regex'            => 'Mobile number must start with 09 and have 11 digits.',
            'mobile_number.unique'           => 'That mobile number is already registered.',
            'barangay.exists'                => 'Please select a valid barangay from the list.',
            'password.min'                   => 'Password must be at least 8 characters.',
            'password_confirmation.required' => 'Please confirm your password.',
            'birthday.before'                => 'You must be at least 18 years old to register.',
            'birthday.after'                 => 'Birthday must be after 1900.',
        ]);

        // ── 2. Resolve the 'resident' role ────────────────────────────────
        $residentRole = UserRole::where('name', 'resident')->first();

        if (! $residentRole) {
            report(new \RuntimeException(
                'UserRole "resident" not found. Run: php artisan db:seed --class=UserRoleSeeder'
            ));
            return response()->json([
                'message' => 'Server configuration error. Please contact support.',
            ], 500);
        }

        // ── 3. Create the user ────────────────────────────────────────────
        $user = DB::transaction(function () use ($validated, $residentRole): User {
            $user = User::create([
                'role_id'           => $residentRole->role_id,
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email']    ?? null,
                'mobile_number'     => $validated['mobile_number'],
                'password'          => Hash::make($validated['password']),
                'barangay'          => $validated['barangay'],
                'birthday'          => $validated['birthday'] ?? null,
                'sex'               => $validated['sex']      ?? null,
                'account_status'    => 'pending',
                'id_verified'       => false,
                'biometric_enabled' => false,
            ]);

            // ── THE FIX ───────────────────────────────────────────────────
            // User::create() returns the model built from the PHP array we
            // passed. At this point `birthday` is still the raw string that
            // came from the request (e.g. "2003-01-01") because Eloquent's
            // $casts only execute when a value is read BACK from the database.
            //
            // refresh() re-fetches the persisted row so every cast fires:
            //   'birthday' => 'date'  →  Carbon instance
            //   'locked_until' => 'datetime'  →  Carbon instance
            // Without this, formatUser() calls ->toDateString() on a plain
            // string and throws: "Call to a member function toDateString()
            // on string".
            $user->refresh();

            return $user;
        });

        // ── 4. Issue Sanctum token ────────────────────────────────────────
        $token = $user->createToken('mobile')->plainTextToken;

        // ── 5. Audit log ──────────────────────────────────────────────────
        $this->logActivity($user, 'REGISTER', [
            'method'   => 'mobile_number',
            'barangay' => $user->barangay,
        ], $request);

        return response()->json([
            'message' => 'Registration successful. Your account is pending approval.',
            'user'    => $this->formatUser($user),
            'token'   => $token,
        ], 201);
    }

    // =========================================================================
    // LOGIN
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string'],
            'password'      => ['required', 'string'],
        ]);

        $mobile = trim($validated['mobile_number']);

        // ── 1. Rate limiting (5 attempts per mobile per minute) ───────────
        $rateLimitKey = 'login|' . $mobile;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'message'     => "Too many login attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        // ── 2. Find user ──────────────────────────────────────────────────
        $user = User::where('mobile_number', $mobile)->first();

        // ── 3. Check lockout ──────────────────────────────────────────────
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'Account locked due to too many failed attempts. '
                           . 'Try again after ' . $user->locked_until->diffForHumans() . '.',
            ], 423);
        }

        // ── 4. Verify credentials ─────────────────────────────────────────
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, 60);

            if ($user) {
                $failCount = $user->failed_login_count + 1;
                $updates   = ['failed_login_count' => $failCount];

                if ($failCount >= 10) {
                    $updates['locked_until'] = now()->addMinutes(30);
                    $this->logActivity($user, 'ACCOUNT_LOCKED', [
                        'reason' => 'too_many_failed_logins',
                    ], $request);
                }

                $user->update($updates);
                $this->logActivity($user, 'LOGIN_FAILED', ['attempt' => $failCount], $request);
            }

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // ── 5. Check account status ───────────────────────────────────────
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

        // ── 6. Reset failed-attempt counter ───────────────────────────────
        RateLimiter::clear($rateLimitKey);
        $user->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => now(),
            'last_login_ip'      => $request->ip(),
        ]);

        // ── 7. Issue Sanctum token ────────────────────────────────────────
        $token = $user->createToken('mobile')->plainTextToken;

        // ── 8. Audit log ──────────────────────────────────────────────────
        $this->logActivity($user, 'LOGIN', ['method' => 'mobile_password'], $request);

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->formatUser($user),
            'token'   => $token,
        ]);
    }

    // =========================================================================
    // LOGOUT
    // =========================================================================

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $this->logActivity($request->user(), 'LOGOUT', [], $request);
        return response()->json(['message' => 'Logged out successfully.']);
    }

    // =========================================================================
    // ME (current user)
    // =========================================================================

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->formatUser($request->user()->load('role')),
        ]);
    }

    // =========================================================================
    // BIOMETRIC — Enable
    // =========================================================================

    public function biometricEnable(Request $request): JsonResponse
    {
        $rawToken = $this->biometricService->enable($request->user(), $request);
        return response()->json([
            'message'         => 'Biometric login enabled.',
            'biometric_token' => $rawToken,
        ]);
    }

    // =========================================================================
    // BIOMETRIC — Disable
    // =========================================================================

    public function biometricDisable(Request $request): JsonResponse
    {
        $this->biometricService->disableAll($request->user());
        return response()->json(['message' => 'Biometric login disabled.']);
    }

    // =========================================================================
    // BIOMETRIC — Login (public route — no auth middleware)
    // =========================================================================

    public function biometricLogin(Request $request): JsonResponse
    {
        $request->validate([
            'biometric_token' => ['required', 'string', 'size:64'],
        ]);

        $key = 'biometric|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'message' => 'Too many biometric attempts. Try password login.',
            ], 429);
        }

        $user = $this->biometricService->validate($request->input('biometric_token'));

        if (! $user) {
            RateLimiter::hit($key, 60);
            return response()->json([
                'message' => 'Biometric token invalid or expired. Please log in with your password.',
            ], 401);
        }

        RateLimiter::clear($key);

        $token = $user->createToken('mobile-biometric')->plainTextToken;

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $this->logActivity($user, 'LOGIN', ['method' => 'biometric'], $request);

        return response()->json([
            'message' => 'Biometric login successful.',
            'user'    => $this->formatUser($user),
            'token'   => $token,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Canonical user shape returned to the mobile app.
     *
     * birthday is cast to Carbon via User::$casts['birthday' => 'date'].
     * After register(), refresh() ensures the cast has fired before we arrive
     * here. For all other entry points (login, me, biometricLogin) the model
     * is loaded fresh from the DB so the cast already applies.
     *
     * The parseBirthday() helper below is a final safety net: if for any reason
     * the cast hasn't fired and birthday is still a raw string, it converts it
     * to Carbon rather than letting toDateString() crash.
     */
    private function formatUser(User $user): array
    {
        return [
            'user_id'           => $user->user_id,
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'email'             => $user->email,
            'mobile_number'     => $user->mobile_number,
            'barangay'          => $user->barangay,
            'birthday'          => $this->parseBirthday($user->birthday),
            'sex'               => $user->sex,
            'account_status'    => $user->account_status,
            'role'              => $user->role?->name,
            'id_verified'       => (bool) $user->id_verified,
            'biometric_enabled' => (bool) $user->biometric_enabled,
            'avatar'            => $user->profile_picture_url ?? $user->avatar,
        ];
    }

    /**
     * Safely convert birthday to a Y-m-d string regardless of whether
     * Eloquent's cast has fired yet.
     *
     *  - null / empty string → null
     *  - Carbon instance     → "Y-m-d"   (cast already ran — normal path)
     *  - raw string          → parsed by Carbon → "Y-m-d"  (safety net)
     */
    private function parseBirthday(mixed $birthday): ?string
    {
        if ($birthday === null || $birthday === '') {
            return null;
        }

        if ($birthday instanceof \DateTimeInterface) {
            return Carbon::instance($birthday)->toDateString();
        }

        // Cast hasn't fired — birthday is still a raw string.
        // Carbon::parse() handles "Y-m-d" and most other date formats.
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
                'metadata'   => array_merge($meta, ['ip' => $request->ip()]),
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable) {
            // Never let logging break the auth flow.
        }
    }
}