<?php
// app/Services/BiometricAuthService.php
//
// Secure biometric exchange-token manager for Ka-Agapay.
//
// Important:
// - The phone never sends real fingerprint/Face ID data to Laravel.
// - The phone stores only a random 64-character exchange token in SecureStore.
// - The biometric_tokens table stores SHA-256 hashes for per-device support.
// - The users.biometric_token_hash legacy column stores Hash::make($rawToken)
//   so your existing AuthController::biometricLogin() using Hash::check()
//   still works.

namespace App\Services;

use App\Models\BiometricToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BiometricAuthService
{
    private const MAX_DEVICES = 5;
    private const TOKEN_TTL_DAYS = 90;

    public function enable(User $user, Request $request): string
    {
        $this->pruneExpired($user);

        $activeCount = BiometricToken::query()
            ->where('user_id', $user->user_id)
            ->where('revoked', false)
            ->count();

        if ($activeCount >= self::MAX_DEVICES) {
            BiometricToken::query()
                ->where('user_id', $user->user_id)
                ->where('revoked', false)
                ->oldest('last_used_at')
                ->first()
                ?->update(['revoked' => true]);
        }

        $rawToken = Str::random(64);

        BiometricToken::query()->create([
            'user_id' => $user->user_id,
            'token_hash' => hash('sha256', $rawToken),
            'device_hint' => $this->deviceHint($request),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
            'revoked' => false,
        ]);

        // Legacy compatibility for your current AuthController::biometricLogin().
        $user->forceFill([
            'biometric_enabled' => true,
            'biometric_token_hash' => Hash::make($rawToken),
        ])->save();

        return $rawToken;
    }

    public function validate(string $rawToken): ?User
    {
        $hash = hash('sha256', $rawToken);

        $record = BiometricToken::query()
            ->with('user.role')
            ->where('token_hash', $hash)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record || !$record->user) {
            return null;
        }

        $record->update([
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
        ]);

        return $record->user;
    }

    public function disableAll(User $user): void
    {
        BiometricToken::query()
            ->where('user_id', $user->user_id)
            ->update(['revoked' => true]);

        $user->forceFill([
            'biometric_enabled' => false,
            'biometric_token_hash' => null,
        ])->save();
    }

    public function revokeSingle(User $user, string $rawToken): bool
    {
        return (bool) BiometricToken::query()
            ->where('user_id', $user->user_id)
            ->where('token_hash', hash('sha256', $rawToken))
            ->update(['revoked' => true]);
    }

    private function pruneExpired(User $user): void
    {
        BiometricToken::query()
            ->where('user_id', $user->user_id)
            ->where('expires_at', '<=', now())
            ->update(['revoked' => true]);
    }

    private function deviceHint(Request $request): string
    {
        return substr($request->header('User-Agent', 'unknown-device'), 0, 100);
    }
}
