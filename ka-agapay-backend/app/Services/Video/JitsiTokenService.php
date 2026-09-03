<?php
// app/Services/Video/JitsiTokenService.php

namespace App\Services\Video;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

/**
 * SINGLE place where Jitsi / JaaS room tokens are minted.
 *
 * Both Telemedicine and Team Chat call into this; neither signs its own token.
 * Previously only Telemedicine signed anything, its signer was hard-wired to a
 * TelemedicineSession's assigned doctor, and Team Chat shipped a literal
 * 'jwt' => null — so staff-to-staff calls attempted an UNAUTHENTICATED join
 * against an 8x8 JaaS tenant, which rejects it.
 *
 * Key handling rules:
 *   • The private key may be an inline PEM or a path to a .pem file. Both are
 *     resolved here.
 *   • The key material is NEVER logged, never returned, and never placed in an
 *     API payload or exception message. Diagnostics report presence/validity
 *     only (see describeConfiguration()).
 */
class JitsiTokenService
{
    /** Resolved PEM, memoised per process so we do not re-read the file per call. */
    private ?string $cachedPrivateKey = null;

    private bool $privateKeyResolved = false;

    public function provider(): string
    {
        return (string) config('services.jitsi.provider', 'self_hosted');
    }

    public function appId(): string
    {
        return (string) config('services.jitsi.app_id', '');
    }

    public function domain(): string
    {
        return (string) config('services.jitsi.domain', '');
    }

    public function roomPrefix(): string
    {
        return (string) config('services.jitsi.room_prefix', 'kaagapay-rhu1');
    }

    public function jwtEnabled(): bool
    {
        return (bool) config('services.jitsi.jwt_enabled', false);
    }

    public function isJaas(): bool
    {
        return $this->provider() === 'jaas';
    }

    /**
     * The JWT 'kid' header, in the compound form 8x8 requires.
     *
     * JaaS expects "<AppID>/<shortKeyId>" and rejects a bare short id with
     * "Key ID (kid) does not match sub". This method used to return
     * config('services.jitsi.api_key') raw, with no concatenation anywhere in
     * the class, so production minted every token with kid="0aa1cb" instead of
     * "vpaas-magic-cookie-.../0aa1cb" and every JaaS join was refused.
     *
     * The short id is the documented contract, not a mistake by whoever
     * configured the droplet: .env.example calls it "API key ID", OPERATIONS.md
     * shows JITSI_API_KEY=<key id>, and the accepted alias is literally
     * JITSI_API_KEY_ID. The operator pasted exactly what the 8x8 console shows
     * and what our own docs asked for; the app simply never did its half.
     * Building the compound value here keeps that contract and means the fix
     * ships with a deploy rather than needing an .env edit on the droplet.
     *
     * Idempotent: a value that already contains '/' is treated as a complete
     * compound id and returned untouched, so an operator who pastes the full
     * string is not punished with "<AppID>/<AppID>/<key>". If that prefix is
     * the WRONG AppID it is deliberately left alone rather than silently
     * rewritten -- `jitsi:doctor` reports the mismatch instead.
     */
    public function keyId(): string
    {
        $raw = trim((string) config('services.jitsi.api_key', ''));

        if ($raw === '') {
            return '';
        }

        // Already compound (correct or not) -- respect what was configured.
        if (str_contains($raw, '/')) {
            return $raw;
        }

        $appId = $this->appId();

        // No tenant to qualify against (self_hosted, or misconfigured JaaS that
        // signJaas() will refuse anyway). Return the bare value unchanged.
        if ($appId === '') {
            return $raw;
        }

        return $appId . '/' . $raw;
    }

    /**
     * Namespace a bare room under the JaaS tenant: {appId}/{room}.
     * Unchanged behaviour — the existing tenant/room naming is preserved.
     */
    public function qualifyRoom(string $roomName): string
    {
        $appId = $this->appId();

        return ($this->isJaas() && $appId !== '')
            ? $appId . '/' . $roomName
            : $roomName;
    }

    /**
     * Mint a room token for ONE identified person.
     *
     * @param string $roomName Bare (un-namespaced) room name.
     * @param array{id:string|int,name:string,email?:?string,avatar?:?string,moderator?:bool} $identity
     */
    public function issueToken(string $roomName, array $identity): ?string
    {
        if (!$this->jwtEnabled()) {
            return null;
        }

        if ($this->provider() === 'meet_public_demo') {
            // The public demo has no token concept.
            return null;
        }

        if (!class_exists(JWT::class)) {
            Log::warning('[JitsiTokenService] firebase/php-jwt is not installed; cannot mint a room token.');

            return null;
        }

        try {
            $ttlMinutes = max(5, (int) config('services.jitsi.jwt_ttl_minutes', 120));
            $now = time();

            $user = [
                'id' => (string) ($identity['id'] ?? 'staff'),
                'name' => trim((string) ($identity['name'] ?? '')) ?: 'RHU Staff',
                'moderator' => (bool) ($identity['moderator'] ?? true),
            ];

            if (!empty($identity['email'])) {
                $user['email'] = (string) $identity['email'];
            }

            if (!empty($identity['avatar'])) {
                $user['avatar'] = (string) $identity['avatar'];
            }

            $payload = [
                'nbf' => $now - 10,
                'exp' => $now + ($ttlMinutes * 60),
                'context' => [
                    'user' => $user,
                    'features' => [
                        'recording' => false,
                        'livestreaming' => false,
                        'transcription' => false,
                        'outbound-call' => false,
                    ],
                ],
            ];

            if ($this->isJaas()) {
                return $this->signJaas($payload, $now);
            }

            return $this->signSelfHosted($payload, $roomName);
        } catch (\Throwable $e) {
            // Deliberately logs only the class + message. Key material never
            // reaches this path, and the message is not allowed to carry it.
            Log::warning('[JitsiTokenService] Failed to mint a Jitsi room token.', [
                'provider' => $this->provider(),
                'error_class' => get_class($e),
                'error' => $this->scrubMessage($e->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * JaaS (8x8) RS256 token. Claim shape is fixed by 8x8:
     *   aud=jitsi, iss=chat, sub={AppID}, room=* , kid header = API key id.
     */
    private function signJaas(array $payload, int $now): ?string
    {
        $appId = $this->appId();
        $keyId = $this->keyId();
        $privateKey = $this->privateKey();

        if ($appId === '' || $keyId === '' || $privateKey === null) {
            Log::warning('[JitsiTokenService] JaaS is enabled but its credentials are incomplete.', [
                'has_app_id' => $appId !== '',
                'has_key_id' => $keyId !== '',
                'has_private_key' => $privateKey !== null,
            ]);

            return null;
        }

        $payload['aud'] = 'jitsi';
        $payload['iss'] = 'chat';
        $payload['sub'] = $appId;
        // '*' authorises every room inside this tenant. The room the client
        // actually opens is still the namespaced one we hand back.
        $payload['room'] = '*';
        $payload['iat'] = $now;

        return JWT::encode($payload, $privateKey, 'RS256', $keyId);
    }

    /** self_hosted HS256 token against the shared app secret. */
    private function signSelfHosted(array $payload, string $roomName): ?string
    {
        $appId = $this->appId();
        $appSecret = (string) config('services.jitsi.app_secret', '');

        // On the JaaS box app_secret holds a PEM path, which is not an HS256
        // secret — guard so we never sign with a filename.
        if ($appSecret === '' || $this->looksLikeKeyMaterialOrPath($appSecret)) {
            return null;
        }

        $payload['aud'] = $appId ?: 'kaagapay';
        $payload['iss'] = $appId ?: 'kaagapay';
        $payload['sub'] = $this->domain() ?: 'kaagapay';
        $payload['room'] = $roomName;

        return JWT::encode($payload, $appSecret, 'HS256');
    }

    /**
     * Resolve the RS256 private key.
     *
     * Accepts, in order of preference:
     *   1. services.jitsi.private_key as an inline PEM
     *   2. services.jitsi.private_key as a filesystem path to a .pem
     *   3. services.jitsi.app_secret as a path (legacy production layout)
     *
     * Returns the PEM string for signing, or null. The value is never logged.
     */
    private function privateKey(): ?string
    {
        if ($this->privateKeyResolved) {
            return $this->cachedPrivateKey;
        }

        $this->privateKeyResolved = true;
        $this->cachedPrivateKey = null;

        $candidates = array_filter([
            (string) config('services.jitsi.private_key', ''),
            // Legacy: the deployed box kept the PEM path here.
            (string) config('services.jitsi.app_secret', ''),
        ], fn ($value) => trim($value) !== '');

        foreach ($candidates as $candidate) {
            $pem = $this->materializePem(trim($candidate));

            if ($pem !== null) {
                $this->cachedPrivateKey = $pem;

                return $this->cachedPrivateKey;
            }
        }

        return null;
    }

    /**
     * Turn a config value into validated PEM text, or null.
     * Never echoes the value itself into logs or exceptions.
     */
    private function materializePem(string $value): ?string
    {
        $pem = null;

        if ($this->looksLikePem($value)) {
            // .env files cannot hold real newlines, so an inline PEM usually
            // arrives with literal \n sequences.
            $pem = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value);
        } elseif ($this->isReadableKeyFile($value)) {
            $contents = @file_get_contents($value);

            if ($contents === false || trim($contents) === '') {
                Log::warning('[JitsiTokenService] Private key file could not be read.', [
                    'path' => $value,
                    'exists' => is_file($value),
                    'readable' => is_readable($value),
                ]);

                return null;
            }

            $pem = $contents;
        } else {
            return null;
        }

        // Validate without ever exposing the material.
        $resource = @openssl_pkey_get_private($pem);

        if ($resource === false) {
            Log::warning('[JitsiTokenService] Private key is present but is not a valid RSA private key.', [
                'source' => $this->looksLikePem($value) ? 'inline' : 'file',
                'path' => $this->looksLikePem($value) ? null : $value,
            ]);

            return null;
        }

        return $pem;
    }

    private function looksLikePem(string $value): bool
    {
        return str_contains($value, '-----BEGIN');
    }

    private function isReadableKeyFile(string $value): bool
    {
        // Guard against treating a random secret as a path.
        if ($value === '' || strlen($value) > 4096 || str_contains($value, "\n")) {
            return false;
        }

        return is_file($value) && is_readable($value);
    }

    private function looksLikeKeyMaterialOrPath(string $value): bool
    {
        return $this->looksLikePem($value)
            || str_ends_with(strtolower($value), '.pem')
            || str_ends_with(strtolower($value), '.key')
            || $this->isReadableKeyFile($value);
    }

    /** Belt-and-braces: never let PEM text escape through an exception message. */
    private function scrubMessage(string $message): string
    {
        if (str_contains($message, '-----BEGIN')) {
            return '[redacted: message contained key material]';
        }

        return mb_substr($message, 0, 300);
    }

    /**
     * True when the provider can actually admit a participant.
     * With JWT enabled that REQUIRES a real token — never treat an
     * unauthenticated JaaS join as "configured".
     */
    public function isUsable(?string $token): bool
    {
        $provider = $this->provider();

        if ($provider === 'meet_public_demo') {
            return true;
        }

        if ($provider === 'self_hosted') {
            return $this->domain() !== ''
                && $this->domain() !== 'meet.kaagapay.local'
                && (!$this->jwtEnabled() || !empty($token));
        }

        if ($provider === 'jaas') {
            return $this->appId() !== '' && (!$this->jwtEnabled() || !empty($token));
        }

        return false;
    }

    /**
     * Identity claims for a staff member joining a call.
     *
     * Team Chat calls are peer-to-peer between staff, so each participant is a
     * moderator of their own room; without that nobody could start the meeting.
     */
    public function identityForUser(?User $user, bool $moderator = true): array
    {
        $name = trim(
            (string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? '')
        );

        return [
            'id' => (string) ($user->user_id ?? 'staff'),
            'name' => $name !== '' ? $name : 'RHU Staff',
            'email' => $user->email ?? null,
            'moderator' => $moderator,
        ];
    }

    /**
     * Assemble the URL a browser actually opens.
     *
     * ORDER MATTERS and was previously wrong on the client: the JWT must be a
     * QUERY parameter, so it has to come BEFORE the '#' fragment. Appending
     * "?jwt=..." after "#config..." buries the token inside the fragment, the
     * server never receives it, and an authenticated JaaS tenant then refuses
     * the join — which looks exactly like "the call just hangs".
     *
     * @param array<int,string> $hashParams e.g. ['config.prejoinPageEnabled=false']
     */
    public function buildJoinUrl(
        string $domain,
        string $fullRoom,
        ?string $jwt,
        array $hashParams = []
    ): string {
        $url = 'https://' . $domain . '/' . ltrim($fullRoom, '/');

        if (!empty($jwt)) {
            $url .= '?jwt=' . rawurlencode($jwt);
        }

        $hashParams = array_values(array_filter($hashParams, fn ($p) => trim((string) $p) !== ''));

        if ($hashParams) {
            $url .= '#' . implode('&', $hashParams);
        }

        return $url;
    }

    /**
     * Non-sensitive configuration snapshot for diagnostics.
     * Reports PRESENCE and validity only — no key id secrets, no PEM contents.
     */
    public function describeConfiguration(): array
    {
        $privateKeyPathCandidate = null;

        foreach ([
            (string) config('services.jitsi.private_key', ''),
            (string) config('services.jitsi.app_secret', ''),
        ] as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && !$this->looksLikePem($candidate) && $this->isReadableKeyFile($candidate)) {
                $privateKeyPathCandidate = $candidate;
                break;
            }
        }

        $pem = $this->privateKey();

        return [
            'provider' => $this->provider(),
            'domain' => $this->domain(),
            'jwt_enabled' => $this->jwtEnabled(),
            'app_id_set' => $this->appId() !== '',
            'key_id_set' => $this->keyId() !== '',
            'private_key_source' => $pem === null
                ? 'unresolved'
                : ($privateKeyPathCandidate !== null ? 'file' : 'inline'),
            'private_key_path' => $privateKeyPathCandidate,
            'private_key_file_exists' => $privateKeyPathCandidate !== null && is_file($privateKeyPathCandidate),
            'private_key_file_readable' => $privateKeyPathCandidate !== null && is_readable($privateKeyPathCandidate),
            'private_key_permissions' => $privateKeyPathCandidate !== null && is_file($privateKeyPathCandidate)
                ? substr(sprintf('%o', fileperms($privateKeyPathCandidate)), -4)
                : null,
            'private_key_valid_rsa' => $pem !== null,
            'php_jwt_installed' => class_exists(JWT::class),
        ];
    }
}
