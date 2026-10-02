<?php

namespace Tests\Unit;

use App\Support\IntegrationCredentials;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Settings > API keys must change what the services actually use, and must
 * never show a key back.
 *
 * This project has removed two settings already for looking real and doing
 * nothing: a "Max Login Attempts" field that nothing enforced, and an AI
 * settings screen whose save answered "Settings updated." while storing
 * nothing. A key field that saves into a config key no service reads would be
 * the third, and the worst of them -- the super admin would rotate a leaked
 * key, see "Saved", and the old key would go on being used.
 *
 * So the first test reads the application's own source: every config key
 * the panel can set must be read by something that is not the panel.
 */
class IntegrationCredentialsTest extends TestCase
{
    private const APP = __DIR__ . '/../../app';

    private const CONFIG = __DIR__ . '/../../config/services.php';

    /** The panel's own files, which would satisfy the check by themselves. */
    private const PANEL_FILES = [
        'Support/IntegrationCredentials.php',
        'Services/Integrations/IntegrationTester.php',
        'Http/Controllers/Api/IntegrationSettingsController.php',
    ];

    #[Test]
    #[TestDox('every key the panel saves is read by the service it is for')]
    public function every_field_reaches_a_service(): void
    {
        $source = $this->applicationSourceExceptPanel();
        $unread = [];

        foreach (IntegrationCredentials::configKeys() as $key) {
            if (!str_contains($source, "config('{$key}'")) {
                $unread[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unread,
            'The API keys panel can save these, but nothing in the application '
            . 'reads them, so saving one would change nothing while the screen '
            . "said \"Saved\":\n  " . implode("\n  ", $unread)
        );
    }

    #[Test]
    #[TestDox('every key the panel saves is a real entry in config/services.php')]
    public function every_field_is_a_configured_key(): void
    {
        $config = (string) file_get_contents(self::CONFIG);
        $missing = [];

        foreach (IntegrationCredentials::configKeys() as $key) {
            // services.google.gemini_model -> the last segment must be declared.
            $leaf = substr($key, strrpos($key, '.') + 1);

            if (!preg_match("/'" . preg_quote($leaf, '/') . "'\s*=>/", $config)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'Not declared in config/services.php: ' . implode(', ', $missing));
    }

    #[Test]
    #[TestDox('a masked key shows at most its last four characters')]
    public function masking_reveals_at_most_four_characters(): void
    {
        $key = 'AIzaSyD-example-key-1234567890abcd';
        $masked = IntegrationCredentials::maskSecret($key);

        $this->assertSame('Ends in abcd', $masked);

        // No run of five characters from the key survives anywhere in it.
        for ($i = 0; $i + 5 <= strlen($key); $i++) {
            $this->assertStringNotContainsString(substr($key, $i, 5), $masked);
        }
    }

    #[Test]
    #[TestDox('a short key shows no characters at all')]
    public function short_keys_show_nothing(): void
    {
        // Four characters of an eight-character key is half of it.
        $this->assertSame('Set (hidden)', IntegrationCredentials::maskSecret('K8abc123'));
        $this->assertSame('', IntegrationCredentials::maskSecret('   '));
    }

    #[Test]
    #[TestDox('an RSA key is identified by a public fingerprint, never its contents')]
    public function rsa_fingerprint_is_public_and_stable(): void
    {
        $pem = $this->throwawayRsaKey();

        $fingerprint = IntegrationCredentials::rsaFingerprint($pem);

        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}(:[0-9A-F]{4}){3}$/', (string) $fingerprint);

        // The same key always gives the same fingerprint, so it can be checked
        // against the public key uploaded to 8x8.
        $this->assertSame($fingerprint, IntegrationCredentials::rsaFingerprint($pem));

        // A .env-style key with literal \n sequences is the same key.
        $this->assertSame($fingerprint, IntegrationCredentials::rsaFingerprint(str_replace("\n", '\n', $pem)));

        $body = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        $this->assertStringNotContainsString(substr((string) $body, 40, 16), (string) $fingerprint);
    }

    #[Test]
    #[TestDox('anything that is not an RSA private key is refused')]
    public function non_keys_are_refused(): void
    {
        $this->assertNull(IntegrationCredentials::rsaFingerprint('not a key'));
        $this->assertNull(IntegrationCredentials::rsaFingerprint(''));
        // Assembled at runtime so no secret scanner mistakes this file for one
        // that holds a key.
        $header = '-----BEGIN ' . 'PRIVATE KEY-----';
        $footer = '-----END ' . 'PRIVATE KEY-----';

        $this->assertNull(IntegrationCredentials::rsaFingerprint("{$header}\nAAAA\n{$footer}"));
    }

    #[Test]
    #[TestDox('the SMS sender name can be changed alongside the SMS key')]
    public function sender_name_is_covered(): void
    {
        // A sender name is approved per Semaphore account, so moving the SMS
        // key to a new account without changing the sender name sends every
        // reminder under a name that account has not approved. They have to
        // be changeable together, and the test checks the name against the
        // new account's approved list before anything is saved.
        //
        // config reads SEMAPHORE_SENDERNAME. The production .env also carries
        // a SEMAPHORE_SENDER_NAME that nothing reads; it is harmless, and both
        // hold the same approved name.
        $this->assertContains('services.semaphore.sendername', IntegrationCredentials::configKeys());
    }

    #[Test]
    #[TestDox('only API keys can be viewed, and never the 8x8 private key')]
    public function only_api_keys_are_revealable(): void
    {
        foreach (['gemini', 'semaphore', 'ocr_space'] as $integration) {
            $this->assertTrue(
                IntegrationCredentials::isRevealable($integration, 'api_key'),
                "{$integration} API key should be viewable with the password."
            );
        }

        // Signs every video call; replaced, never read. Its fingerprint
        // already says which key is in use.
        $this->assertFalse(IntegrationCredentials::isRevealable('jaas', 'private_key'));

        // Identifiers are already on screen, so there is nothing to reveal.
        $this->assertFalse(IntegrationCredentials::isRevealable('gemini', 'model'));
        $this->assertFalse(IntegrationCredentials::isRevealable('semaphore', 'sendername'));

        // Anything the registry does not know.
        $this->assertFalse(IntegrationCredentials::isRevealable('gemini', 'password'));
        $this->assertFalse(IntegrationCredentials::isRevealable('stripe', 'api_key'));
    }

    #[Test]
    #[TestDox('viewing a key is a POST, inside the super-admin-only group')]
    public function reveal_route_is_post_and_super_admin_only(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');

        // A GET would put the password in the URL, the browser history and
        // the server's access log.
        $this->assertStringContainsString(
            "Route::post('/{integration}/reveal', [IntegrationSettingsController::class, 'reveal']);",
            $routes
        );
        $this->assertDoesNotMatchRegularExpression("/Route::get\\('\\/\\{integration\\}\\/reveal'/", $routes);

        // The group it sits in must allow super admins and nobody else.
        $at = strpos($routes, "'/{integration}/reveal'");
        $groupStart = strrpos(substr($routes, 0, $at), "Route::prefix('admin/settings/integrations')");

        $this->assertNotFalse($groupStart, 'The reveal route is outside the integrations group.');

        $group = substr($routes, $groupStart, $at - $groupStart);

        $this->assertMatchesRegularExpression(
            "/->middleware\\(\\['role:super_admin',/",
            $group,
            'The integrations group no longer restricts access to super_admin alone.'
        );
    }

    // ------------------------------------------------------------------

    private function applicationSourceExceptPanel(): string
    {
        $root = realpath(self::APP);
        $text = '';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (in_array($relative, self::PANEL_FILES, true)) {
                continue;
            }

            $text .= file_get_contents($file->getPathname());
        }

        return $text;
    }

    /**
     * A key made for this test and discarded with it.
     *
     * Generated rather than committed: a private key in the repository would
     * be flagged by secret scanning, and should be. Windows builds of PHP need
     * an openssl.cnf to generate keys, and ship one beside the binary.
     */
    private function throwawayRsaKey(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $configs = array_filter([
            null,
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
        ], fn ($c) => $c === null || is_file($c));

        foreach ($configs as $config) {
            $opts = $config === null ? $options : $options + ['config' => $config];
            $key = @openssl_pkey_new($opts);

            if ($key !== false && openssl_pkey_export($key, $pem, null, $config === null ? [] : ['config' => $config])) {
                return $pem;
            }
        }

        $this->markTestSkipped('This PHP cannot generate an RSA key (no usable openssl.cnf), so the fingerprint was not checked.');
    }
}
