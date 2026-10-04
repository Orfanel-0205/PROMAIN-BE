<?php
// app/Services/Integrations/IntegrationTester.php

namespace App\Services\Integrations;

use App\Services\Notification\AccountMailService;
use App\Services\Video\JitsiTokenService;
use App\Support\IntegrationCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Proves a key works before it is saved.
 *
 * A key that is pasted and saved without a test is how a typo takes the
 * chatbot or the SMS reminders down silently -- the panel says "Saved", and
 * the first sign of trouble is a resident who never got a text. So a save is
 * a test first, and a key that fails is not stored.
 *
 * EACH TEST CHECKS WHAT A GOOD KEY RETURNS, NOT JUST A STATUS CODE
 * ----------------------------------------------------------------
 * Semaphore answers an invalid key with HTTP 200 and an error in the body,
 * so "the request succeeded" proves nothing; the test looks for the account
 * fields only a real key returns. OCR.space is asked to read nothing, which a
 * valid key answers with "no content provided" (E400) and an invalid one with
 * E555. Gemini is asked for a one-word reply with the chosen model, so a key
 * that cannot use that model fails here rather than in the chatbot.
 *
 * 8x8 is the exception and says so. There is no 8x8 endpoint that confirms a
 * key pair without starting a call, so the test proves what can be proved
 * locally -- the App ID and key ID are well formed and belong together, the
 * private key is a real RSA key, and a token signs -- and the result tells
 * the super admin that 8x8 itself confirms the key on the first call.
 *
 * NEVER ECHO A KEY
 * ----------------
 * Messages returned to the browser are built from the provider's error text
 * with the candidate key scrubbed out, and Gemini's key travels in a header
 * rather than the URL so it cannot appear in an exception message either.
 */
final class IntegrationTester
{
    private const TIMEOUT_SECONDS = 20;

    /**
     * @param array<string, string> $candidate every field, submitted or current
     * @param array<string, string> $submitted only the fields filled in
     * @return array{ok:bool, message:string, warning?:string, details?:array<string, mixed>}
     */
    public function test(string $integration, array $candidate, array $submitted = []): array
    {
        try {
            return match ($integration) {
                'gemini'    => $this->gemini($candidate),
                'semaphore' => $this->semaphore($candidate),
                'ocr_space' => $this->ocrSpace($candidate),
                'jaas'      => $this->jaas($candidate, $submitted),
                'email'     => $this->email($candidate),
                default     => $this->fail('Unknown integration.'),
            };
        } catch (ConnectionException) {
            return $this->fail('The service could not be reached. Check the server\'s connection and try again; nothing was saved.');
        } catch (\Throwable $e) {
            return $this->fail('The test could not be completed: ' . $this->scrub($e->getMessage(), $candidate));
        }
    }

    // ------------------------------------------------------------------

    /** @param array<string, string> $c */
    private function gemini(array $c): array
    {
        $key = $c['api_key'] ?? '';
        $model = $c['model'] !== '' ? $c['model'] : 'gemini-2.5-flash';

        if ($key === '') {
            return $this->fail('Enter a Gemini API key.');
        }

        // Model names go into the URL path, so only the characters they use.
        if (preg_match('/^[a-z0-9][a-z0-9.\-]{1,80}$/i', $model) !== 1) {
            return $this->fail('That does not look like a Gemini model name, for example gemini-2.5-flash.');
        }

        // Same request as `php artisan gemini:test`. 2.5-flash spends part of
        // its output budget thinking, so the budget is not set to the size
        // of the expected answer.
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['x-goog-api-key' => $key])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Say "OK" only.']]]],
                'generationConfig' => ['maxOutputTokens' => 256],
            ]);

        $status = $response->status();
        $reason = $this->scrub((string) data_get($response->json(), 'error.message', ''), $c);

        return match (true) {
            $status === 200 => $this->pass("Gemini answered using {$model}.", ['model' => $model]),
            $status === 429 => [
                // The key is genuine; Google is limiting how fast it is used.
                'ok' => true,
                'message' => "The key is valid and can use {$model}.",
                'warning' => 'Google is rate-limiting this key right now. The assistant may refuse some questions until the limit resets.',
                'details' => ['model' => $model],
            ],
            $status === 404 => $this->fail("This key cannot use the model {$model}, or that model does not exist. Try a current model such as gemini-2.5-flash."),
            in_array($status, [400, 401, 403], true) => $this->fail('Google did not accept this key' . ($reason !== '' ? ": {$reason}" : '.')),
            $status >= 500 => $this->fail('Google is not responding properly at the moment. Nothing was saved; try again shortly.'),
            default => $this->fail("Unexpected response from Google (HTTP {$status})" . ($reason !== '' ? ": {$reason}" : '.')),
        };
    }

    /** @param array<string, string> $c */
    private function semaphore(array $c): array
    {
        $key = $c['api_key'] ?? '';
        $sender = $c['sendername'] ?? '';
        $base = rtrim((string) config('services.semaphore.base_url', 'https://api.semaphore.co/api/v4'), '/');

        if ($key === '') {
            return $this->fail('Enter a Semaphore API key.');
        }

        // Semaphore takes its key as a query parameter by design.
        $account = Http::timeout(self::TIMEOUT_SECONDS)->get("{$base}/account", ['apikey' => $key])->json();
        $account = is_array($account) && array_is_list($account) ? ($account[0] ?? []) : $account;

        // HTTP 200 with {"apikey":["The selected apikey is invalid."]} is how
        // Semaphore refuses a key, so look for fields only an account has.
        if (!is_array($account) || !array_key_exists('credit_balance', $account)) {
            return $this->fail('Semaphore did not accept this key.');
        }

        $balance = (int) $account['credit_balance'];
        $accountStatus = (string) ($account['status'] ?? '');

        if ($accountStatus !== '' && strcasecmp($accountStatus, 'Active') !== 0) {
            return $this->fail("The key works, but the Semaphore account is {$accountStatus}, so messages will not send.");
        }

        $names = Http::timeout(self::TIMEOUT_SECONDS)->get("{$base}/account/sendernames", ['apikey' => $key])->json();
        $approved = collect(is_array($names) ? $names : [])
            ->filter(fn ($row) => is_array($row) && strcasecmp((string) ($row['status'] ?? ''), 'Active') === 0)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();

        $details = [
            'credit_balance' => $balance,
            'account_name' => (string) ($account['account_name'] ?? ''),
            'approved_sender_names' => $approved,
        ];

        // Semaphore accepts a message from an unapproved sender name rather
        // than refusing it, so this is the only place the mistake shows.
        if ($sender !== '' && $approved !== [] && !in_array($sender, $approved, true)) {
            return $this->fail(
                "The sender name \"{$sender}\" is not approved on this Semaphore account. "
                . 'Approved: ' . implode(', ', $approved) . '.',
                $details,
            );
        }

        $result = $this->pass(
            "Key accepted. Balance: {$balance} credits"
                . ($sender !== '' ? ", and \"{$sender}\" is an approved sender name." : '.'),
            $details,
        );

        if ($balance < 100) {
            $result['warning'] = "Only {$balance} credits left. Top up before reminders stop sending.";
        }

        return $result;
    }

    /** @param array<string, string> $c */
    private function ocrSpace(array $c): array
    {
        $key = $c['api_key'] ?? '';

        if ($key === '') {
            return $this->fail('Enter an OCR.space API key.');
        }

        // An empty request: a valid key is told there is nothing to read
        // (E400), an invalid one is refused (E555, HTTP 403). It reads no
        // image and should not count against the monthly allowance.
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['apikey' => $key])
            ->asMultipart()
            ->post('https://api.ocr.space/parse/image', [['name' => 'language', 'contents' => 'eng']]);

        $body = (string) $response->body();

        if ($response->status() === 403 || str_contains($body, 'E555')) {
            return $this->fail('OCR.space did not accept this key.');
        }

        if (str_contains($body, 'E400') || stripos($body, 'No content') !== false) {
            return $this->pass('Key accepted by OCR.space.');
        }

        return $this->fail('Unexpected response from OCR.space: ' . Str::limit($this->scrub($body, $c), 160));
    }

    /**
     * @param array<string, string> $c
     * @param array<string, string> $submitted
     */
    private function jaas(array $c, array $submitted): array
    {
        $appId = $c['app_id'] ?? '';
        $keyId = $c['key_id'] ?? '';

        if (preg_match('/^vpaas-magic-cookie-[a-f0-9]{32}$/i', $appId) !== 1) {
            return $this->fail('The App ID should look like vpaas-magic-cookie- followed by 32 letters and digits, as shown in the 8x8 console.');
        }

        if ($keyId === '') {
            return $this->fail('Enter the API key ID from the 8x8 console.');
        }

        // A compound key ID names its own tenant, and it must be this one.
        if (str_contains($keyId, '/') && !str_starts_with($keyId, $appId . '/')) {
            return $this->fail('That API key ID belongs to a different 8x8 App ID.');
        }

        $fingerprint = null;

        if (isset($submitted['private_key'])) {
            $fingerprint = IntegrationCredentials::rsaFingerprint($submitted['private_key']);

            if ($fingerprint === null) {
                return $this->fail('The private key is not a valid RSA private key. Paste the whole file, including the BEGIN and END lines.');
            }
        }

        // Sign a real token with the candidate values, exactly as a call
        // would, then put config back as it was.
        $overrides = [
            'services.jitsi.app_id'  => $appId,
            'services.jitsi.api_key' => $keyId,
        ];

        if (isset($submitted['private_key'])) {
            $overrides['services.jitsi.private_key'] = $submitted['private_key'];
        }

        $previous = [];

        foreach (array_keys($overrides) as $configKey) {
            $previous[$configKey] = config($configKey);
        }

        try {
            config($overrides);

            $tokens = new JitsiTokenService();

            if (!$tokens->isJaas()) {
                return $this->fail('The server is not set to use 8x8 (JITSI_PROVIDER is not "jaas"), so these values would not be used.');
            }

            $token = $tokens->issueToken('kaagapay-integration-test', $tokens->identityForUser(null));
        } finally {
            config($previous);
        }

        if (empty($token)) {
            return $this->fail('A token could not be signed with these values. Check that the key ID and private key are from the same 8x8 key pair.');
        }

        return [
            'ok' => true,
            'message' => 'The values are well formed and a valid token was signed.',
            'warning' => '8x8 confirms the key itself only when a call starts. Start a test call after saving.',
            'details' => array_filter(['fingerprint' => $fingerprint]),
        ];
    }

    /**
     * Sign in to the mailbox without sending anything.
     *
     * Connecting and logging in is exactly what a send does first, so a pass
     * here means reset codes will go out. Nothing is sent, so the test does
     * not fill the mailbox or count against Gmail's daily sending limit.
     *
     * @param array<string, string> $c
     */
    private function email(array $c): array
    {
        $address = AccountMailService::deliverableAddress($c['address'] ?? '');
        $password = AccountMailService::appPassword($c['app_password'] ?? '');

        if ($address === null) {
            return $this->fail('Enter the Gmail address that will send account email, for example kaagapay.rhu@gmail.com.');
        }

        if ($password === '') {
            return $this->fail('Enter an app password for that Gmail account.');
        }

        $config = AccountMailService::transportConfig($address, $password);

        try {
            $transport = new EsmtpTransport($config['host'], $config['port'], $config['scheme'] === 'smtps');
            $transport->setUsername($address);
            $transport->setPassword($password);

            $stream = $transport->getStream();

            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }

            $transport->start();
            $transport->stop();
        } catch (TransportExceptionInterface $e) {
            $reason = $this->scrub($e->getMessage(), $c + ['password' => $password]);

            // 535 is "username and password not accepted".
            if (str_contains($reason, '535') || stripos($reason, 'authenticat') !== false) {
                return $this->fail(
                    'Gmail did not accept this address and app password. Use a 16-letter app password '
                    . '(Google Account > Security > 2-Step Verification > App passwords), not the normal Gmail password.'
                );
            }

            return $this->fail("Could not connect to {$config['host']}: " . Str::limit($reason, 160));
        }

        return $this->pass("Signed in to {$address}. Password reset codes will also be sent by email.", ['address' => $address]);
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $details */
    private function pass(string $message, array $details = []): array
    {
        return array_filter(['ok' => true, 'message' => $message, 'details' => $details], fn ($v) => $v !== []);
    }

    /** @param array<string, mixed> $details */
    private function fail(string $message, array $details = []): array
    {
        return array_filter(['ok' => false, 'message' => $message, 'details' => $details], fn ($v) => $v !== []);
    }

    /**
     * Remove every candidate value from a message before it leaves the server.
     *
     * @param array<string, string> $candidate
     */
    private function scrub(string $message, array $candidate): string
    {
        foreach ($candidate as $value) {
            if (strlen((string) $value) >= 6) {
                $message = str_replace((string) $value, '[hidden]', $message);
            }
        }

        return trim($message);
    }
}
