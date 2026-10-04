<?php

namespace Tests\Unit;

use App\Services\Notification\AccountMailService;
use App\Services\Notification\AccountSmsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Forgot password", on the resident app and the admin sign-in page.
 *
 * A reset code is the one code sent without a password, so the things held
 * here are the ones that keep that from becoming a leak or a bill: the
 * replies must not say whether an account exists, the requests must be rate
 * limited, the texts must be cheap and discreet, and email must never go to
 * the placeholder addresses the demo staff accounts were created with.
 */
class PasswordResetTest extends TestCase
{
    private const AUTH = __DIR__ . '/../../app/Http/Controllers/Api/AuthController.php';

    private const ROUTES = __DIR__ . '/../../routes/api.php';

    private const LIMITS = __DIR__ . '/../../app/Providers/RouteServiceProvider.php';

    #[Test]
    #[TestDox('the reset texts cost one credit each and name nothing they unlock')]
    public function the_texts_are_short_and_discreet(): void
    {
        $texts = [
            'code' => sprintf(AccountSmsService::PASSWORD_RESET_CODE_MESSAGE, '123456'),
            'notice' => AccountSmsService::PASSWORD_RESET_NOTICE,
        ];

        foreach ($texts as $name => $text) {
            $this->assertLessThanOrEqual(160, strlen($text), "The reset {$name} text costs two credits.");

            foreach (['api', 'key', 'admin', 'super', 'settings', 'gemini', 'semaphore'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $text, "The reset {$name} text mentions \"{$word}\".");
            }
        }

        // Anyone can ask for a reset with someone else's number; the owner
        // who did not ask must be told it changed nothing.
        $this->assertStringContainsString('unchanged', $texts['code']);
    }

    #[Test]
    #[TestDox('email skips the placeholder addresses the demo accounts use')]
    public function placeholder_addresses_get_no_email(): void
    {
        foreach ([
            'superadmin09119190001@kaagapay.local',
            'probe09990000901@example.invalid',
            'someone@example.com',
            'x@localhost',
            'not-an-email',
            '',
            null,
        ] as $address) {
            $this->assertNull(AccountMailService::deliverableAddress($address), "Would email {$address}.");
        }

        $this->assertSame('juan.delacruz@gmail.com', AccountMailService::deliverableAddress('  Juan.DelaCruz@Gmail.com '));
    }

    #[Test]
    #[TestDox('an app password pasted in groups of four still works')]
    public function app_password_spaces_are_ignored(): void
    {
        $this->assertSame('abcdefghijklmnop', AccountMailService::appPassword('abcd efgh ijkl mnop'));
        $this->assertSame('abcdefghijklmnop', AccountMailService::appPassword(" abcd\tefgh ijkl mnop\n"));
    }

    #[Test]
    #[TestDox('asking for a code never says whether the account exists')]
    public function the_request_reply_is_the_same_for_everyone(): void
    {
        $source = (string) file_get_contents(self::AUTH);
        $start = (string) substr($source, (int) strpos($source, 'private function startPasswordReset('), 5000);
        $start = substr($start, 0, (int) strpos($start, 'private function finishPasswordReset('));

        // A stand-in challenge for every case without a real one.
        $this->assertStringContainsString('$codes->issueDecoy(', $start);

        // Exactly one reply after the input check, and it is a 200.
        $this->assertSame(2, substr_count($start, 'return response()->json('), 'startPasswordReset gained a second kind of reply.');
        $this->assertStringNotContainsStringIgnoringCase('not found', $start);
        $this->assertStringNotContainsString('], 404)', $start);
        $this->assertStringNotContainsString('masked_mobile', $start, 'The reply shows where the code went, which only real accounts have.');

        // A failed resend answers like a sent one, since stand-ins never fail.
        $resend = (string) substr($source, (int) strpos($source, 'private function resendPasswordResetCode('), 2500);
        $this->assertMatchesRegularExpression("/'sent', 'send_failed' =>/", $resend);
    }

    #[Test]
    #[TestDox('a reply takes the same time whether or not a code was sent')]
    public function reply_time_does_not_depend_on_the_account(): void
    {
        // Measured on production before this rule: a real request answered in
        // 0.5s (Semaphore), a stand-in in 1.4s. Timing alone told them apart.
        $source = (string) file_get_contents(self::AUTH);

        foreach (['private function startPasswordReset(', 'private function resendPasswordResetCode('] as $method) {
            $body = (string) substr($source, (int) strpos($source, $method), 5000);
            $evened = strpos($body, '$this->answerNoSoonerThan(');
            $reply = strrpos(substr($body, 0, (int) strpos($body, "\n    }\n")), 'return ');

            $this->assertNotFalse($evened, "{$method} replies without evening out the time.");
            $this->assertLessThan($reply, $evened, "{$method} replies before evening out the time.");
        }
    }

    #[Test]
    #[TestDox('a reset signs out every device and tells the account holder')]
    public function a_reset_signs_out_and_notifies(): void
    {
        $source = (string) file_get_contents(self::AUTH);
        $finish = (string) substr($source, (int) strpos($source, 'private function finishPasswordReset('), 5000);
        $finish = substr($finish, 0, (int) strpos($finish, 'private function resendPasswordResetCode('));

        foreach ([
            '$user->tokens()->delete()' => 'signs out other devices',
            '$codes->retireAll($user)' => 'retires pending codes',
            "'failed_login_count' => 0" => 'clears the wrong-password check',
            "'password_reset_at'" => 'pauses viewing API keys',
            'sendPasswordResetNotice($user)' => 'tells the account holder',
            'PasswordPolicyService::standard()' => 'applies the password policy',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $finish, "A reset no longer {$what}.");
        }

        // The new password is validated before the code is checked, so a
        // weak password does not cost one of the five attempts.
        $this->assertLessThan(strpos($finish, '$codes->verify('), strpos($finish, 'PasswordPolicyService::standard()'));
    }

    #[Test]
    #[TestDox('asking is limited per account and per IP per day; entering is limited per challenge')]
    public function reset_routes_are_throttled(): void
    {
        $routes = (string) file_get_contents(self::ROUTES);

        $recovery = (string) substr($routes, (int) strpos($routes, "Route::middleware('throttle:auth-recovery')"), 500);
        $this->assertStringContainsString("Route::post('/forgot-password'", $recovery);
        $this->assertStringContainsString("Route::post('/admin/forgot-password'", $recovery);

        $codeGroups = explode("Route::middleware('throttle:auth-code')", $routes);
        $entering = (string) substr(end($codeGroups), 0, 700);

        foreach (['/reset-password', '/forgot-password/resend', '/admin/reset-password', '/admin/forgot-password/resend'] as $path) {
            $this->assertStringContainsString("Route::post('{$path}'", $entering, "{$path} is not in a code-throttled group.");
        }

        $limits = (string) file_get_contents(self::LIMITS);
        $this->assertMatchesRegularExpression("/Limit::perDay\\(\\d+\\)->by\\('recover-day:'/", $limits);
    }

    #[Test]
    #[TestDox('viewing API keys pauses after a reset by code')]
    public function reveal_pauses_after_a_reset(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/IntegrationSettingsController.php');
        $reveal = (string) substr($controller, (int) strpos($controller, 'public function reveal('), 7000);
        $reveal = substr($reveal, 0, (int) strpos($reveal, '$this->codes->issue('));

        $this->assertStringContainsString('password_reset_at', $reveal);
        $this->assertStringContainsString('], 423)', $reveal);
    }
}
