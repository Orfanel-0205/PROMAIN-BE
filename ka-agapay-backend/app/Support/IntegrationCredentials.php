<?php
// app/Support/IntegrationCredentials.php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * API keys for outside services, changeable from the admin without a server.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every key the system depends on -- Gemini, Semaphore, OCR.space, 8x8 -- lived
 * only in the server's .env file, and only someone with SSH access could change
 * one. That made the developer a permanent dependency: when the LGU moves these
 * services to its own accounts, or a key is leaked, or a free tier runs out,
 * the RHU could not act on its own. A super admin can now paste a new key, and
 * the system tests it before using it.
 *
 * HOW A SAVED KEY TAKES EFFECT
 * ----------------------------
 * Nothing that uses these keys was changed. Every one of them -- 31 reads across
 * the chatbot, SMS, OCR and video code -- goes through config('services...'),
 * so applyOverrides() sets those config values at the start of each request and
 * every reader sees the saved key without knowing it came from here. With
 * nothing saved, config keeps its .env values and behaviour is exactly what it
 * was before this file existed. In particular, the SMS code is untouched,
 * which keeps this project's standing rule about the SMS pipeline: the panel
 * changes which key Semaphore is handed, never how a message is sent.
 *
 * WHAT IS PROTECTED, AND FROM WHAT
 * --------------------------------
 *   Encrypted at rest, with the application key. The daily database backup
 *   therefore holds ciphertext, not keys.
 *
 *   Never sent back to the browser. status() reports where each value comes
 *   from and the last four characters of a secret, nothing more, so a screen
 *   that is photographed or shared does not leak a key.
 *
 *   Not written to the config cache. `php artisan config:cache` boots the
 *   application and writes every config value to a PHP file in plain text;
 *   applying saved keys during that command would put them there. Those
 *   commands are skipped, and the next real request applies them as usual.
 *
 *   Cached as ciphertext. The per-request lookup is cached so it costs no
 *   database query, and what is cached is the encrypted value.
 */
final class IntegrationCredentials
{
    /**
     * Each integration, its fields, and the config key each one sets.
     *
     * A field is listed here only if something in the application reads its
     * config key -- a test holds that true, because a field that is saved and
     * read by nothing is the "control that looks real and does nothing" this
     * project has removed twice already.
     *
     * Secrets are never shown back. Identifiers are: the Gemini model, the SMS
     * sender name, and the 8x8 App ID and key ID all appear in ordinary
     * traffic anyway (the sender on every text, the App ID in every room URL).
     */
    public const REGISTRY = [
        'gemini' => [
            'label' => 'Google Gemini (AI assistant)',
            'fields' => [
                'api_key' => ['label' => 'API key', 'config' => 'services.google.gemini_api_key', 'secret' => true],
                'model'   => ['label' => 'Model', 'config' => 'services.google.gemini_model', 'secret' => false],
            ],
        ],
        'semaphore' => [
            'label' => 'Semaphore (SMS)',
            'fields' => [
                'api_key'    => ['label' => 'API key', 'config' => 'services.semaphore.api_key', 'secret' => true],
                'sendername' => ['label' => 'Sender name', 'config' => 'services.semaphore.sendername', 'secret' => false],
            ],
        ],
        'ocr_space' => [
            'label' => 'OCR.space (ID reading)',
            'fields' => [
                'api_key' => ['label' => 'API key', 'config' => 'services.ocr_space.key', 'secret' => true],
            ],
        ],
        'jaas' => [
            'label' => '8x8 Jitsi as a Service (video calls)',
            'fields' => [
                'app_id'      => ['label' => 'App ID', 'config' => 'services.jitsi.app_id', 'secret' => false],
                'key_id'      => ['label' => 'API key ID', 'config' => 'services.jitsi.api_key', 'secret' => false],
                'private_key' => ['label' => 'Private key (PEM)', 'config' => 'services.jitsi.private_key', 'secret' => true],
            ],
        ],
    ];

    /**
     * Secrets that cannot be viewed, even with the password.
     *
     * The 8x8 private key signs every video call. Nobody ever needs to read
     * it -- only to replace it, by generating a new pair in the 8x8 console --
     * and its fingerprint already identifies which key is in use. Showing a
     * private key in a browser would add a way to leak it and no way to use it.
     */
    public const NOT_REVEALABLE = ['jaas.private_key'];

    /** Commands that write configuration to disk; saved keys stay out of it. */
    private const CONFIG_WRITING_COMMANDS = ['config:cache', 'optimize'];

    private const CACHE_KEY = 'integration_credentials.ciphertext';

    /** One warning per request is enough when a key will not decrypt. */
    private static bool $warnedUndecryptable = false;

    /** @return array<int, string> */
    public static function integrations(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function exists(string $integration): bool
    {
        return array_key_exists($integration, self::REGISTRY);
    }

    /** @return array<string, array{label:string, config:string, secret:bool}> */
    public static function fields(string $integration): array
    {
        return self::REGISTRY[$integration]['fields'] ?? [];
    }

    /**
     * Whether a field may be shown in full after the password is re-entered.
     *
     * Only secrets: identifiers are already on screen. Never the ones listed
     * in NOT_REVEALABLE.
     */
    public static function isRevealable(string $integration, string $field): bool
    {
        $meta = self::fields($integration)[$field] ?? null;

        return $meta !== null
            && $meta['secret']
            && !in_array($integration . '.' . $field, self::NOT_REVEALABLE, true);
    }

    /**
     * The value in effect for a field right now: saved here, or from .env.
     *
     * Read from config, which applyOverrides() has already updated for this
     * request, so it is exactly what the service itself is using.
     */
    public static function currentValue(string $integration, string $field): string
    {
        $meta = self::fields($integration)[$field] ?? null;

        return $meta === null ? '' : trim((string) config($meta['config'], ''));
    }

    /** Every config key the panel can set. @return array<int, string> */
    public static function configKeys(): array
    {
        $keys = [];

        foreach (self::REGISTRY as $definition) {
            foreach ($definition['fields'] as $meta) {
                $keys[] = $meta['config'];
            }
        }

        return $keys;
    }

    /**
     * Put saved keys into config for this request.
     *
     * Called from AppServiceProvider::boot(), before any controller or command
     * resolves a service that reads one. Any failure leaves config as .env set
     * it: a settings table that cannot be read must never take the chatbot or
     * SMS down with it.
     */
    public static function applyOverrides(): void
    {
        if (self::isWritingConfig()) {
            return;
        }

        try {
            $rows = self::storedCiphertext();
        } catch (\Throwable $e) {
            Log::warning('[IntegrationCredentials] Saved keys could not be loaded; using server defaults.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $overrides = [];

        foreach (self::REGISTRY as $integration => $definition) {
            foreach ($definition['fields'] as $field => $meta) {
                $plain = self::decrypt($rows[self::storageKey($integration, $field)] ?? null);

                if ($plain !== null && $plain !== '') {
                    $overrides[$meta['config']] = $plain;
                }
            }
        }

        if ($overrides !== []) {
            config($overrides);
        }
    }

    /**
     * The values a test should try: what was submitted, over what is in use.
     *
     * A blank field means "keep the current one", so a super admin can change
     * the Gemini model without pasting the key again.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, string>
     */
    public static function candidate(string $integration, array $submitted): array
    {
        $candidate = [];

        foreach (self::fields($integration) as $field => $meta) {
            $value = self::clean($submitted[$field] ?? null);
            $candidate[$field] = $value !== '' ? $value : trim((string) config($meta['config'], ''));
        }

        return $candidate;
    }

    /**
     * Only the fields that were actually filled in.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, string>
     */
    public static function submittedFields(string $integration, array $submitted): array
    {
        $filled = [];

        foreach (self::fields($integration) as $field => $meta) {
            $value = self::clean($submitted[$field] ?? null);

            if ($value !== '') {
                $filled[$field] = $value;
            }
        }

        return $filled;
    }

    /**
     * Store the filled-in fields, encrypted. Blank fields are left as they are.
     *
     * Callers test first. This only stores.
     *
     * @param array<string, string> $fields
     * @return array<int, string> the field names saved
     */
    public static function save(string $integration, array $fields, ?int $userId): array
    {
        $saved = [];

        foreach (self::fields($integration) as $field => $meta) {
            $value = self::clean($fields[$field] ?? null);

            if ($value === '') {
                continue;
            }

            AppSetting::query()->updateOrCreate(
                [
                    'group'  => AppSetting::GROUP_INTEGRATIONS,
                    'rhu_id' => AppSetting::SHARED_RHU_ID,
                    'key'    => self::storageKey($integration, $field),
                ],
                [
                    'value'      => Crypt::encryptString($value),
                    'type'       => 'encrypted',
                    'updated_by' => $userId,
                ],
            );

            $saved[] = $field;
        }

        self::forgetCache();

        return $saved;
    }

    /** Remove the saved values, so the server's .env values apply again. */
    public static function clear(string $integration): int
    {
        $keys = array_map(
            fn (string $field) => self::storageKey($integration, $field),
            array_keys(self::fields($integration)),
        );

        $deleted = AppSetting::query()
            ->where('group', AppSetting::GROUP_INTEGRATIONS)
            ->where('rhu_id', AppSetting::SHARED_RHU_ID)
            ->whereIn('key', $keys)
            ->delete();

        self::forgetCache();

        return $deleted;
    }

    /**
     * What the panel shows. Never a secret.
     *
     * Each field says where its value comes from -- saved here, the server's
     * .env, or nowhere -- because "it works" and "it works on the key the
     * developer set two years ago" are different facts for the person in
     * charge of these accounts.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $rows = self::storedRows();
        $out = [];

        foreach (self::REGISTRY as $integration => $definition) {
            $fields = [];
            $latestAt = null;
            $latestBy = null;
            $customised = false;

            foreach ($definition['fields'] as $field => $meta) {
                $row = $rows[self::storageKey($integration, $field)] ?? null;
                $savedPlain = $row ? self::decrypt($row->value) : null;
                $effective = trim((string) config($meta['config'], ''));

                $source = match (true) {
                    $savedPlain !== null && $savedPlain !== '' => 'saved',
                    $row !== null => 'unreadable',
                    $effective !== '' || self::hasServerFallback($integration, $field) => 'server',
                    default => 'none',
                };

                if ($row !== null) {
                    $customised = true;

                    if ($latestAt === null || $row->updated_at > $latestAt) {
                        $latestAt = $row->updated_at;
                        $latestBy = $row->updated_by;
                    }
                }

                $fields[$field] = [
                    'label'   => $meta['label'],
                    'secret'  => $meta['secret'],
                    // Whether the page offers "View". Still checked again,
                    // with the password, when a reveal is actually requested.
                    'revealable' => self::isRevealable($integration, $field) && $source !== 'none',
                    'source'  => $source,
                    'display' => $meta['secret']
                        ? self::describeSecret($integration, $field, $effective)
                        : ($effective !== '' ? $effective : null),
                ];
            }

            $out[$integration] = [
                'label'      => $definition['label'],
                'customised' => $customised,
                'fields'     => $fields,
                'updated_at' => $latestAt ? \Illuminate\Support\Carbon::parse($latestAt)->toIso8601String() : null,
                'updated_by' => $latestBy ? (User::query()->find($latestBy)?->full_name ?: 'Unknown user') : null,
            ];
        }

        return $out;
    }

    /**
     * The last four characters, and nothing for short values.
     *
     * Four is enough to tell two keys apart on screen and too little to be
     * useful to anyone who sees it. A value shorter than twelve characters
     * shows no tail at all, because four of eight is half the key.
     */
    public static function maskSecret(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (strlen($value) < 12) {
            return 'Set (hidden)';
        }

        return 'Ends in ' . substr($value, -4);
    }

    /**
     * A public fingerprint of an RSA private key, or null if it is not one.
     *
     * Derived from the public half, so it can be compared with the public key
     * uploaded to the 8x8 console without exposing anything secret.
     */
    public static function rsaFingerprint(string $pem): ?string
    {
        $pem = str_replace(['\\r\\n', '\\n', '\\r'], "\n", trim($pem));

        $key = @openssl_pkey_get_private($pem);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return null;
        }

        $hash = strtoupper(substr(hash('sha256', (string) $details['key']), 0, 16));

        return implode(':', str_split($hash, 4));
    }

    // ------------------------------------------------------------------

    private static function describeSecret(string $integration, string $field, string $effective): ?string
    {
        if ($integration === 'jaas' && $field === 'private_key') {
            // The server's default lives as a file path in JITSI_APP_SECRET,
            // which JitsiTokenService reads after private_key.
            $value = $effective !== '' ? $effective : trim((string) config('services.jitsi.app_secret', ''));

            if ($value === '') {
                return null;
            }

            $pem = str_contains($value, '-----BEGIN')
                ? $value
                : ((is_file($value) && is_readable($value)) ? (string) @file_get_contents($value) : '');

            $fingerprint = $pem !== '' ? self::rsaFingerprint($pem) : null;

            return $fingerprint !== null
                ? 'RSA key, fingerprint ' . $fingerprint
                : 'Set, but not a readable RSA private key';
        }

        return $effective !== '' ? self::maskSecret($effective) : null;
    }

    /** The 8x8 key can come from JITSI_APP_SECRET when private_key is empty. */
    private static function hasServerFallback(string $integration, string $field): bool
    {
        return $integration === 'jaas'
            && $field === 'private_key'
            && trim((string) config('services.jitsi.app_secret', '')) !== '';
    }

    private static function storageKey(string $integration, string $field): string
    {
        return $integration . '.' . $field;
    }

    private static function clean(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private static function isWritingConfig(): bool
    {
        if (!app()->runningInConsole()) {
            return false;
        }

        return in_array($_SERVER['argv'][1] ?? '', self::CONFIG_WRITING_COMMANDS, true);
    }

    /** @return array<string, string> storage key => ciphertext */
    private static function storedCiphertext(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            if (!Schema::hasTable('app_settings')) {
                return [];
            }

            return AppSetting::query()
                ->where('group', AppSetting::GROUP_INTEGRATIONS)
                ->where('rhu_id', AppSetting::SHARED_RHU_ID)
                ->pluck('value', 'key')
                ->all();
        });
    }

    /** @return array<string, AppSetting> storage key => row, uncached, for the panel */
    private static function storedRows(): array
    {
        if (!Schema::hasTable('app_settings')) {
            return [];
        }

        return AppSetting::query()
            ->where('group', AppSetting::GROUP_INTEGRATIONS)
            ->where('rhu_id', AppSetting::SHARED_RHU_ID)
            ->get()
            ->keyBy('key')
            ->all();
    }

    private static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Ciphertext back to the key, or null.
     *
     * Fails when APP_KEY has changed since the value was saved. The value is
     * then ignored rather than fatal, so the service falls back to .env, and
     * the panel reports it as "unreadable" so someone knows to save it again.
     */
    private static function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            if (!self::$warnedUndecryptable) {
                self::$warnedUndecryptable = true;
                Log::warning('[IntegrationCredentials] A saved key could not be decrypted; '
                    . 'the server default is being used. APP_KEY may have changed since it was saved.');
            }

            return null;
        }
    }
}
