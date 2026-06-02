<?php
// app/Services/PasswordPolicyService.php
// Enforces Ka-agapay password policy:
//   • Min 8 chars
//   • At least 1 uppercase, 1 lowercase, 1 digit, 1 special char
//   • Not in the top-200 common password list
//   • Not equal to (or containing) the user's own name / mobile number

namespace App\Services;

use Illuminate\Validation\Rules\Password;

class PasswordPolicyService
{
    // ── Common passwords blocklist (abbreviated; extend as needed) ────────
    private const COMMON_PASSWORDS = [
        'password', 'password1', 'password123', 'Password1', 'Password1!',
        '12345678', '123456789', '1234567890', 'qwerty123', 'iloveyou',
        'welcome1', 'admin123', 'letmein1', 'sunshine1', 'monkey123',
        'dragon123', 'master123', 'abcdef12', 'passw0rd', 'pass@123',
    ];

    // ── Laravel Password rule object (use in FormRequest) ────────────────

    /**
     * Returns a Laravel Password rule pre-configured with Ka-agapay policy.
     *
     * Usage in FormRequest rules():
     *   'password' => ['required', 'confirmed', PasswordPolicyService::rules()]
     */
    public static function rules(): Password
    {
        return Password::min(8)
            ->max(128)
            ->mixedCase()       // upper + lower
            ->numbers()
            ->symbols()
            ->uncompromised();  // checks HaveIBeenPwned API (optional, remove if offline)
    }

    // ── Programmatic check (use where FormRequest is unavailable) ─────────

    /**
     * @return array{valid: bool, errors: string[]}
     */
    public static function validate(
        string $password,
        ?string $firstName = null,
        ?string $lastName  = null,
        ?string $mobile    = null
    ): array {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        if (!preg_match('/[\W_]/', $password)) {
            $errors[] = 'Password must contain at least one special character (e.g. @, !, #).';
        }

        // Blocklist check (case-insensitive)
        if (in_array(strtolower($password), array_map('strtolower', self::COMMON_PASSWORDS), true)) {
            $errors[] = 'That password is too common. Please choose a more unique one.';
        }

        // Must not contain user's own name or mobile number
        foreach (array_filter([$firstName, $lastName, $mobile]) as $personal) {
            if (stripos($password, $personal) !== false) {
                $errors[] = 'Password must not contain your name or mobile number.';
                break;
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    // ── Strength score (0–4) for frontend UX ─────────────────────────────

    public static function strengthScore(string $password): int
    {
        $score = 0;
        if (strlen($password) >= 8)           $score++;
        if (preg_match('/[A-Z]/', $password))  $score++;
        if (preg_match('/[0-9]/', $password))  $score++;
        if (preg_match('/[\W_]/', $password))  $score++;
        return $score;
    }
}