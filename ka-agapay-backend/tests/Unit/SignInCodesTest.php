<?php

namespace Tests\Unit;

use App\Services\Auth\VerificationCodes;
use App\Services\Notification\AccountSmsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * SMS codes: after a wrong password at sign-in, and every time a super admin
 * views an API key.
 *
 * Most of what makes the codes safe is in the database and only shows end to
 * end. What can be held here is the part that would fail quietly: the shape
 * of a code, how it is stored, what the text message says and costs, and the
 * HTTP contract the two clients depend on -- including the one detail that
 * would turn a security feature into a broken login, the status code.
 */
class SignInCodesTest extends TestCase
{
    private const AUTH = __DIR__ . '/../../app/Http/Controllers/Api/AuthController.php';

    private const ROUTES = __DIR__ . '/../../routes/api.php';

    #[Test]
    #[TestDox('a code is always six digits, leading zeros kept')]
    public function codes_are_six_digits(): void
    {
        for ($i = 0; $i < 2000; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', VerificationCodes::generateCode());
        }
    }

    #[Test]
    #[TestDox('only a keyed hash of the code is stored, bound to its challenge')]
    public function codes_are_stored_as_bound_hashes(): void
    {
        $key = 'base64:test-key-not-the-real-one';
        $hash = VerificationCodes::hashCode('challenge-a', '123456', $key);

        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString('123456', $hash);

        // Same code, same challenge: same hash, so it can be checked.
        $this->assertSame($hash, VerificationCodes::hashCode('challenge-a', '123456', $key));

        // Same code under another challenge must not verify there.
        $this->assertNotSame($hash, VerificationCodes::hashCode('challenge-b', '123456', $key));

        // Without the app key, six digits are a million guesses -- trivial
        // against a plain hash. Keyed, a stolen table is not enough.
        $this->assertNotSame($hash, hash('sha256', 'challenge-a|123456'));
        $this->assertNotSame($hash, VerificationCodes::hashCode('challenge-a', '123456', 'another-key'));
    }

    #[Test]
    #[TestDox('the text costs one credit and says nothing about what it unlocks')]
    public function the_text_is_short_and_discreet(): void
    {
        $text = sprintf(AccountSmsService::VERIFICATION_CODE_MESSAGE, '123456');

        // Over 160 characters and Semaphore bills two credits for every code.
        $this->assertLessThanOrEqual(160, strlen($text));

        // A code sent to a wrong or recycled number is read by a stranger.
        foreach (['api', 'key', 'admin', 'super', 'settings', 'gemini', 'semaphore'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text, "The code text mentions \"{$word}\".");
        }

        $this->assertStringContainsString('123456', $text);
    }

    #[Test]
    #[TestDox('a code is required with HTTP 403, never 200')]
    public function code_required_is_never_a_success_status(): void
    {
        $source = (string) file_get_contents(self::AUTH);

        // The mobile app's current build treats any 200 from /login as a
        // finished sign-in and stores whatever token it holds -- here, none.
        $at = strpos($source, "'code_required' => true,");
        $this->assertNotFalse($at, 'The code_required response was not found.');

        $close = strpos($source, '], ', $at);
        $this->assertSame('], 403)', substr($source, $close, 7));
    }

    #[Test]
    #[TestDox('the code is sent only after the password has been checked')]
    public function code_is_sent_only_after_the_password(): void
    {
        $source = (string) file_get_contents(self::AUTH);

        foreach (['public function adminLogin(', 'public function login('] as $method) {
            $start = strpos($source, $method);
            $body = substr($source, $start, 6000);

            $password = strpos($body, 'Hash::check(');
            $stepUp = strpos($body, '$this->stepUpIfNeeded(');

            $this->assertNotFalse($password, "{$method} no longer checks the password.");
            $this->assertNotFalse($stepUp, "{$method} no longer asks for a code after a wrong password.");

            // Guessing passwords must never make the system send a text.
            $this->assertLessThan($stepUp, $password, "{$method} can send a code before checking the password.");
        }
    }

    #[Test]
    #[TestDox('the code endpoints are public POSTs behind their own throttle')]
    public function code_routes_are_throttled_posts(): void
    {
        $routes = (string) file_get_contents(self::ROUTES);
        $group = strpos($routes, "Route::middleware('throttle:auth-code')->group(function () {");

        $this->assertNotFalse($group, 'The sign-in code routes lost their throttle.');

        $block = substr($routes, $group, 600);

        foreach (['/login/verify-code', '/login/resend-code', '/admin/login/verify-code', '/admin/login/resend-code'] as $path) {
            $this->assertStringContainsString("Route::post('{$path}'", $block, "{$path} is missing or not a POST in the throttled group.");
        }
    }

    #[Test]
    #[TestDox('viewing a key takes a second step, inside the super-admin group')]
    public function reveal_needs_a_code_and_stays_super_admin_only(): void
    {
        $routes = (string) file_get_contents(self::ROUTES);
        $group = strrpos(
            substr($routes, 0, (int) strpos($routes, "'/{integration}/reveal/confirm'")),
            "Route::prefix('admin/settings/integrations')"
        );

        $this->assertNotFalse($group, 'The reveal confirm route is outside the integrations group.');
        $this->assertMatchesRegularExpression(
            "/->middleware\\(\\['role:super_admin',/",
            substr($routes, $group, 400)
        );

        $controller = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/IntegrationSettingsController.php');
        $reveal = substr($controller, (int) strpos($controller, 'public function reveal('), 7000);
        $reveal = substr($reveal, 0, (int) strpos($reveal, 'public function revealConfirm('));

        // The first step must hand out a code, not the key.
        $this->assertStringContainsString('$this->codes->issue(', $reveal);
        $this->assertStringNotContainsString("'value' =>", $reveal, 'reveal() returns a key without the SMS code.');
    }
}
