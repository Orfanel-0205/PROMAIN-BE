<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\VerificationCodes;
use Illuminate\Console\Command;

/**
 * Let one account sign in again without an SMS code.
 *
 *   php artisan auth:clear-sign-in-check 09171234567
 *   php artisan auth:clear-sign-in-check someone@example.com
 *
 * The last resort. After a wrong password an account needs a code texted to
 * its phone; if that phone is lost or the number on file is wrong, RHU staff
 * normally fix it by setting a new password from the Users page. This is for
 * when there is nobody left to do that -- a super admin whose own number is
 * wrong, say -- and it needs someone with access to the server, which is the
 * point: it is not reachable from the web.
 *
 * It clears the failed-login count, any lockout, and any codes in flight. It
 * does not change the password. Fix the mobile number on the account
 * afterwards, or the next mistyped password ends in the same place.
 */
class ClearSignInCheck extends Command
{
    protected $signature = 'auth:clear-sign-in-check {login : The account\'s mobile number or email}';

    protected $description = 'Let an account sign in without an SMS code (clears failed logins, lockout and pending codes)';

    public function handle(VerificationCodes $codes): int
    {
        $login = trim((string) $this->argument('login'));
        $digits = preg_replace('/\D/', '', $login) ?? '';

        if (preg_match('/^63(9\d{9})$/', $digits, $m) === 1) {
            $digits = '0' . $m[1];
        }

        $user = User::query()
            ->where(function ($q) use ($login, $digits) {
                if ($digits !== '') {
                    $q->where('mobile_number', $digits);
                }

                $q->orWhereRaw('LOWER(email) = ?', [strtolower($login)]);
            })
            ->first();

        if (!$user) {
            $this->error('No account matches that mobile number or email.');

            return self::FAILURE;
        }

        $name = trim((string) $user->first_name . ' ' . (string) $user->last_name) ?: 'that account';

        if (!$this->confirm("Clear the sign-in check for {$name} (user #{$user->user_id})?", true)) {
            return self::SUCCESS;
        }

        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
        ])->save();

        $codes->retireAll($user);

        $this->info("Done. {$name} can sign in with their password alone.");
        $this->line('Check the mobile number on the account, so the next code reaches the right phone.');

        return self::SUCCESS;
    }
}
