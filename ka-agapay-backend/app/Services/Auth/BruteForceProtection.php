<?php
// app/Services/Auth/BruteForceProtection.php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Cache;

class BruteForceProtection
{
    private int $lockoutMinutes = 15;

    /**
     * Failed logins allowed before this mobile number is locked out.
     *
     * SHARES ONE SETTING WITH THE RATE LIMITER, deliberately.
     *
     * There are two independent per-account guards on login: the auth-login
     * rate limiter (requests per minute) and this one (failures before a
     * 15-minute lockout). Both used to hardcode 5. When the admin Settings
     * page gained a real, enforced "Max Login Attempts" field, wiring only the
     * rate limiter would have produced a subtler version of the bug the field
     * was fixing: setting it to 10 would still lock the account at 5, because
     * this class had never heard of the setting.
     *
     * Reading the same value in both places is what makes the number on screen
     * mean what it says. Unset it falls back to 5 -- the value both guards
     * used before -- so behaviour is unchanged until somebody chooses to
     * change it.
     */
    private function maxAttempts(): int
    {
        return AppSettings::maxLoginAttempts();
    }

    public function recordFailedAttempt(string $mobile): void
    {
        $key      = "login_attempts:{$mobile}";
        $attempts = Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes($this->lockoutMinutes));

        if ($attempts >= $this->maxAttempts()) {
            User::where('mobile_number', $mobile)->update([
                'failed_login_count' => $attempts,
                'locked_until'       => now()->addMinutes($this->lockoutMinutes),
            ]);
        }
    }

    public function clearAttempts(string $mobile): void
    {
        Cache::forget("login_attempts:{$mobile}");
        User::where('mobile_number', $mobile)->update([
            'failed_login_count' => 0,
            'locked_until'       => null,
        ]);
    }

    public function isLocked(string $mobile): bool
    {
        return Cache::get("login_attempts:{$mobile}", 0) >= $this->maxAttempts();
    }
}