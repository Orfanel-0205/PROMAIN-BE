<?php
// app/Http/Controllers/Api/AuthController.php
// UPDATED:
//   - Admin login accepts: super_admin, rhu_admin, mho, doctor, nurse, midwife, bhw
//   - Separate adminLogin endpoint returns role capabilities
//   - Web admin SPA uses /admin/login; mobile keeps /login
//   - formatUser includes role_permissions for web admin routing

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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
    // =========================================================================
    // ADMIN WEB LOGIN  POST /api/v1/admin/login
    // Accepts: super_admin | rhu_admin | mho | doctor | nurse | midwife | bhw
    // Returns role_permissions so the SPA can show/hide menu items.
    // =========================================================================

    /**
     * Roles allowed to access the web admin panel.
     * Extend this array to grant more roles access.
     */
    private const ADMIN_ROLES = [
        'super_admin',
        'rhu_admin',
        'mho',
        'doctor',
        'nurse',
        'midwife',
        'bhw',
    ];

    /**
     * Role capability map — drives which sections are visible in the SPA.
     *  full_access  → all CMS + analytics + user management
     *  cms          → events + announcements create/edit/publish
     *  analytics    → read-only analytics + reports
     *  queue        → queue management only
     *  telemedicine → telemedicine sessions only
     */
    private const ROLE_CAPABILITIES = [
        'super_admin' => ['full_access', 'cms', 'analytics', 'queue', 'telemedicine', 'user_management'],
        'rhu_admin'   => ['full_access', 'cms', 'analytics', 'queue', 'telemedicine', 'user_management'],
        'mho'         => ['cms', 'analytics', 'queue', 'telemedicine'],
        'doctor'      => ['telemedicine', 'queue'],
        'nurse'       => ['queue', 'telemedicine'],
        'midwife'     => ['queue'],
        'bhw'         => ['queue'],
    ];

    public function adminLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string'],
            'password'      => ['required', 'string'],
        ]);

        $mobile       = trim($validated['mobile_number']);
        $rateLimitKey = 'admin_login|' . $mobile . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'message'     => "Too many attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::with('role')->where('mobile_number', $mobile)->first();

        // Check lockout
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'Account temporarily locked. Try again after '
                           . $user->locked_until->diffForHumans() . '.',
            ], 423);
        }

        // Credential check
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, 60);

            if ($user) {
                $failCount = $user->failed_login_count + 1;
                $updates   = ['failed_login_count' => $failCount];
                if ($failCount >= 10) {
                    $updates['locked_until'] = now()->addMinutes(30);
                }
                $user->update($updates);
            }

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // Role gate — only admin roles can log in here
        $roleName = $user->role?->name;
        if (!in_array($roleName, self::ADMIN_ROLES)) {
            return response()->json([
                'message' => 'Access denied. This portal is for RHU staff only.',
            ], 403);
        }

        // Account status check
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

        // Success — reset rate limiter and counters
        RateLimiter::clear($rateLimitKey);
        $user->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
            'last_login_at'      => now(),
            'last_login_ip'      => $request->ip(),
        ]);

        $token = $user->createToken('web-admin')->plainTextToken;

        $this->logActivity($user, 'ADMIN_LOGIN', ['method' => 'mobile_password', 'role' => $roleName], $request);

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->formatAdminUser($user, $roleName),
            'token'   => $token,
        ]);
    }

    // =========================================================================
    // MOBILE LOGIN  POST /api/v1/login
    // Residents only — blocks admin roles from mobile (or allow all, see comment)
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string'],
            'password'      => ['required', 'string'],
        ]);

        $mobile       = trim($validated['mobile_number']);
        $rateLimitKey = 'login|' . $mobile;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'message'     => "Too many login attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::with('role')->where('mobile_number', $mobile)->first();

        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'Account locked. Try again after ' . $user->locked_until->diffForHumans() . '.',
            ], 423);
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, 60);
            if ($user) {
                $failCount = $user->failed_login_count + 1;
                $updates   = ['failed_login_count' => $failCount];
                if ($failCount >= 10) {
                    $updates['locked_until'] = now()->addMinutes(30);
                }
                $user->update($updates);
            }
            return response()->json(['message' => 'Invalid credentials.'], 401);
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
        $this->logActivity($user, 'LOGIN', ['method' => 'mobile_password'], $request);

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
            'barangay'              => ['required', 'string', 'max:150', Rule::exists('barangays', 'name')],
            'birthday'              => ['nullable', 'date', 'before:' . now()->subYears(18)->toDateString(), 'after:1900-01-01'],
            'sex'                   => ['nullable', Rule::in(['male', 'female', 'other'])],
        ]);

        $residentRole = UserRole::where('name', 'resident')->firstOrFail();

        $user = DB::transaction(function () use ($validated, $residentRole): User {
            $user = User::create([
                'role_id'           => $residentRole->role_id,
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email'] ?? null,
                'mobile_number'     => $validated['mobile_number'],
                'password'          => Hash::make($validated['password']),
                'barangay'          => $validated['barangay'],
                'birthday'          => $validated['birthday'] ?? null,
                'sex'               => $validated['sex'] ?? null,
                'account_status'    => 'pending',
                'id_verified'       => false,
                'biometric_enabled' => false,
            ]);
            $user->refresh();
            return $user;
        });

        $token = $user->createToken('mobile')->plainTextToken;
        $this->logActivity($user, 'REGISTER', ['barangay' => $user->barangay], $request);

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
        return response()->json(['message' => 'Logged out successfully.']);
    }

    // =========================================================================
    // GET /api/v1/me
    // =========================================================================

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');
        $role = $user->role?->name;

        // Return richer payload for admin users
        if (in_array($role, self::ADMIN_ROLES)) {
            return response()->json(['user' => $this->formatAdminUser($user, $role)]);
        }

        return response()->json(['user' => $this->formatUser($user)]);
    }

    // =========================================================================
    // BIOMETRIC LOGIN  POST /api/v1/biometric/login
    // =========================================================================

    public function biometricLogin(Request $request): JsonResponse
    {
        $request->validate(['biometric_token' => ['required', 'string', 'size:64']]);

        $key = 'biometric|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Too many biometric attempts.'], 429);
        }

        $hash = hash('sha256', $request->input('biometric_token'));
        $user = User::where('biometric_token_hash', $hash)
            ->where('biometric_enabled', true)
            ->where('account_status', 'active')
            ->with('role')
            ->first();

        if (!$user) {
            RateLimiter::hit($key, 60);
            return response()->json(['message' => 'Biometric token invalid or expired.'], 401);
        }

        RateLimiter::clear($key);
        $token = $user->createToken('mobile-biometric')->plainTextToken;
        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);
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

    private function formatAdminUser(User $user, string $roleName): array
    {
        return array_merge($this->formatUser($user), [
            'capabilities' => self::ROLE_CAPABILITIES[$roleName] ?? [],
        ]);
    }

    private function parseBirthday(mixed $birthday): ?string
    {
        if ($birthday === null || $birthday === '') return null;
        if ($birthday instanceof \DateTimeInterface) return Carbon::instance($birthday)->toDateString();
        try { return Carbon::parse((string) $birthday)->toDateString(); } catch (\Throwable) { return null; }
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
        } catch (\Throwable) {}
    }
}