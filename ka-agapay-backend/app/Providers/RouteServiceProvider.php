<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        /*
         * Phase 3 — auth rate limiting.
         *
         * The public auth routes previously shared ONE `throttle:5,1` bucket
         * keyed only by IP. Every RHU workstation sits behind a single shared
         * municipal connection, so five combined login / OTP / password-reset
         * attempts per minute were shared by ALL staff at both facilities: one
         * person fumbling a password locked out everyone else.
         *
         * Each limiter below returns TWO limits, so both failure modes are
         * covered without punishing the shared IP:
         *
         *   - per account  — stops guessing at one user's password
         *   - per IP       — stops enumeration across many accounts, set wide
         *                    enough that a busy RHU never trips it normally
         *
         * These sit ON TOP of BruteForceProtection (5 failures per mobile
         * number, 15-minute lockout), which is per-account and survives across
         * IPs. Rate limiting bounds the request rate; brute-force protection
         * bounds the failure count. Neither replaces the other.
         */
        // Laravel invokes a Limit response callback as ($request, $headers) --
        // it must accept those, not an int. Getting this wrong turns every
        // throttled request into a 500 instead of a 429.
        $tooMany = fn ($request, array $headers = []) => response()->json([
            'message' => 'Too many attempts. Please wait a moment before trying again.',
        ], 429, $headers);

        // Login (resident, admin, biometric).
        RateLimiter::for('auth-login', function (Request $request) use ($tooMany) {
            $identifier = Str::lower(trim((string) (
                $request->input('mobile_number')
                ?? $request->input('email')
                ?? $request->input('phone')
                ?? ''
            )));

            return [
                Limit::perMinute(5)->by('login:' . $identifier . '|' . $request->ip())->response($tooMany),
                Limit::perMinute(30)->by('login-ip:' . $request->ip())->response($tooMany),
            ];
        });

        // Password reset REQUEST and OTP resend. Tighter than login because
        // each accepted request can send a real SMS — this bounds both account
        // enumeration and Semaphore spend.
        RateLimiter::for('auth-recovery', function (Request $request) use ($tooMany) {
            $identifier = Str::lower(trim((string) (
                $request->input('mobile_number')
                ?? $request->input('email')
                ?? ''
            )));

            return [
                Limit::perMinute(3)->by('recover:' . $identifier . '|' . $request->ip())->response($tooMany),
                Limit::perMinute(15)->by('recover-ip:' . $request->ip())->response($tooMany),
            ];
        });

        // Registration-invite verification and acceptance. The SPA replays the
        // signed link once per page load, so this must tolerate refreshes while
        // still bounding signature-guessing against the invite token.
        RateLimiter::for('auth-invite', function (Request $request) use ($tooMany) {
            return [
                Limit::perMinute(10)->by('invite:' . (string) $request->query('token') . '|' . $request->ip())->response($tooMany),
                Limit::perMinute(20)->by('invite-ip:' . $request->ip())->response($tooMany),
            ];
        });

        // Retained: the original name, still referenced by nothing, kept so an
        // older cached route file cannot fail to resolve 'throttle:auth'.
        RateLimiter::for('auth', function (Request $request) use ($tooMany) {
            return Limit::perMinute(5)->by($request->ip())->response($tooMany);
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
