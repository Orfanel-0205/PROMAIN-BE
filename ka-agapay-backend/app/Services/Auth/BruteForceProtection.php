<?php
// app/Services/Auth/BruteForceProtection.php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class BruteForceProtection
{
    private int $maxAttempts    = 5;
    private int $lockoutMinutes = 15;

    public function recordFailedAttempt(string $mobile): void
    {
        $key      = "login_attempts:{$mobile}";
        $attempts = Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes($this->lockoutMinutes));

        if ($attempts >= $this->maxAttempts) {
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
        return Cache::get("login_attempts:{$mobile}", 0) >= $this->maxAttempts;
    }
}