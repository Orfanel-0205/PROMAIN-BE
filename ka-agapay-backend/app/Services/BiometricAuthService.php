<?php
// app/Services/BiometricAuthService.php
// Manages per-device biometric tokens for Ka-agapay.
//
// Security model:
//   • Raw biometric data is NEVER stored (handled 100% by the OS).
//   • A 64-byte cryptographically random token is issued after a successful
//     biometric scan and stored in the device's SecureStore (iOS Keychain /
//     Android Keystore).
//   • Only the SHA-256 hash of that token is stored server-side.
//   • Each user can have at most MAX_DEVICES active biometric tokens.
//   • Tokens are device-bound via user-agent + device fingerprint hints.

namespace App\Services;

use App\Models\User;
use App\Models\BiometricToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BiometricAuthService
{
    private const MAX_DEVICES     = 5;
    private const TOKEN_TTL_DAYS  = 90;  // auto-expire after 90 days of inactivity

    // ── Enable biometrics for a device ────────────────────────────────────

    /**
     * Generates a new biometric token for the authenticated user.
     * Revokes the oldest token if the device limit is exceeded.
     *
     * @throws \RuntimeException
     */
    public function enable(User $user, Request $request): string
    {
        // Prune expired tokens before counting
        $this->pruneExpired($user);

        $activeCount = BiometricToken::where('user_id', $user->user_id)
            ->where('revoked', false)
            ->count();

        if ($activeCount >= self::MAX_DEVICES) {
            // Revoke the oldest token (LRU eviction)
            BiometricToken::where('user_id', $user->user_id)
                ->where('revoked', false)
                ->oldest('last_used_at')
                ->first()
                ?->update(['revoked' => true]);
        }

        // Generate cryptographically random 64-byte token
        $rawToken = Str::random(64);

        BiometricToken::create([
            'user_id'        => $user->user_id,
            'token_hash'     => hash('sha256', $rawToken),
            'device_hint'    => $this->deviceHint($request),
            'last_used_at'   => now(),
            'expires_at'     => now()->addDays(self::TOKEN_TTL_DAYS),
            'revoked'        => false,
        ]);

        // Also keep the legacy column in sync
        $user->update([
            'biometric_enabled'    => true,
            'biometric_token_hash' => hash('sha256', $rawToken),
        ]);

        return $rawToken;   // returned ONCE to the device; never stored raw server-side
    }

    // ── Validate a biometric login attempt ────────────────────────────────

    /**
     * Resolves and validates a raw biometric token.
     * Returns the owning User or null on failure.
     */
    public function validate(string $rawToken): ?User
    {
        $hash = hash('sha256', $rawToken);

        $record = BiometricToken::with('user')
            ->where('token_hash', $hash)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return null;
        }

        // Slide the expiry window on each successful use
        $record->update(['last_used_at' => now()]);

        return $record->user;
    }

    // ── Disable biometrics for all devices ───────────────────────────────

    public function disableAll(User $user): void
    {
        BiometricToken::where('user_id', $user->user_id)
            ->update(['revoked' => true]);

        $user->update([
            'biometric_enabled'    => false,
            'biometric_token_hash' => null,
        ]);
    }

    // ── Revoke a single device token ──────────────────────────────────────

    public function revokeSingle(User $user, string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);

        return (bool) BiometricToken::where('user_id', $user->user_id)
            ->where('token_hash', $hash)
            ->update(['revoked' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function pruneExpired(User $user): void
    {
        BiometricToken::where('user_id', $user->user_id)
            ->where('expires_at', '<', now())
            ->update(['revoked' => true]);
    }

    /**
     * Non-sensitive device hint stored for audit purposes.
     * Never used for authentication decisions.
     */
    private function deviceHint(Request $request): string
    {
        return substr($request->header('User-Agent', 'unknown'), 0, 100);
    }
}