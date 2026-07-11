<?php
// app/Services/RegistrationInviteService.php
//
// Panelist requirement (Sir Ayco) — signed, unique, expiring, ONE-TIME staff
// registration links.
//
// Uses Laravel's native signing primitives only (URL::temporarySignedRoute /
// URL::hasCorrectSignature / URL::signatureHasNotExpired) — no hand-rolled
// crypto. The signature is computed against the CANONICAL app root
// (config('app.url')) on both generation and verification, so links survive
// reverse-proxy host rewriting and links generated from the CLI verify
// identically to links generated over HTTP.
//
// Validation order is the panelist's explicit order, each failure mode with
// its own honest error: (1) signature, (2) expiry, (3) token exists,
// (4) revoked, (5) already used.

namespace App\Services;

use App\Models\RegistrationInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class RegistrationInviteService
{
    /** Route NAME of the public signed verification endpoint. */
    public const VERIFY_ROUTE = 'admin.register.invite.verify';

    /** Allowed lifetimes (minutes) — 30 min quick link, 1 day, 7 days. */
    public const ALLOWED_LIFETIMES = [30, 1440, 10080];

    /** Default: 7 days, per the panelist's "Registration Sign-in URL (7 days)". */
    public const DEFAULT_LIFETIME_MINUTES = 10080;

    /**
     * Create ONE invite and return the shareable signed link.
     *
     * @return array{invite: RegistrationInvite, url: string, signed_api_url: string, token: string}
     */
    public function generate(
        ?int $createdBy = null,
        ?string $intendedFor = null,
        ?string $mobileLock = null,
        int $lifetimeMinutes = self::DEFAULT_LIFETIME_MINUTES
    ): array {
        if (!in_array($lifetimeMinutes, self::ALLOWED_LIFETIMES, true)) {
            $lifetimeMinutes = self::DEFAULT_LIFETIME_MINUTES;
        }

        $token = Str::random(64); // unguessable; unique per registration request
        $expiresAt = Carbon::now()->addMinutes($lifetimeMinutes);

        $invite = RegistrationInvite::create([
            'token_hash' => hash('sha256', $token),
            'intended_for' => $intendedFor !== null ? Str::limit(trim($intendedFor), 150, '') : null,
            'mobile_number' => $this->normalizeMobile($mobileLock),
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        $signedApiUrl = $this->withCanonicalRoot(
            fn () => URL::temporarySignedRoute(self::VERIFY_ROUTE, $expiresAt, ['token' => $token])
        );

        // The link handed to the person points at the ADMIN SPA registration
        // page and carries the exact same signed query string; the SPA replays
        // it against the API verification route.
        $spaUrl = rtrim((string) config('app.admin_app_url'), '/')
            . '/register?' . (string) parse_url($signedApiUrl, PHP_URL_QUERY);

        return [
            'invite' => $invite,
            'url' => $spaUrl,
            'signed_api_url' => $signedApiUrl,
            'token' => $token,
        ];
    }

    /**
     * Validate the invite parameters in the panelist's required order, with a
     * distinct code/message/HTTP status per failure mode.
     *
     * @param array{token?: mixed, expires?: mixed, signature?: mixed} $params
     * @return array{ok: bool, code: string, message: string, status: int, invite: ?RegistrationInvite}
     */
    public function validateParams(array $params): array
    {
        $token = (string) ($params['token'] ?? '');
        $expires = (string) ($params['expires'] ?? '');
        $signature = (string) ($params['signature'] ?? '');

        if ($token === '' || $expires === '' || $signature === '') {
            return $this->failure(
                'missing_invite',
                'This registration page can only be opened through an official invitation link from the RHU administrator.',
                422
            );
        }

        // Rebuild the exact URL that was signed and let Laravel verify it.
        $fakeRequest = $this->rebuiltRequest($token, $expires, $signature);

        // 1 — signature (tamper check) BEFORE anything else.
        if (!URL::hasCorrectSignature($fakeRequest)) {
            return $this->failure(
                'invalid_signature',
                'This registration link is invalid or has been modified. Please use the exact link you were given.',
                403
            );
        }

        // 2 — expiry (both the signed `expires` param and the DB column below).
        if (!URL::signatureHasNotExpired($fakeRequest)) {
            return $this->failure(
                'expired',
                'This registration link has expired. Please ask the RHU administrator for a new one.',
                410
            );
        }

        // 3 — the token must belong to a real, recorded invitation.
        $invite = RegistrationInvite::where('token_hash', hash('sha256', $token))->first();

        if (!$invite) {
            return $this->failure(
                'not_found',
                'This registration link is not recognized. Please ask the RHU administrator for a new one.',
                404
            );
        }

        if ($invite->isRevoked()) {
            return $this->failure(
                'revoked',
                'This registration link was cancelled by the RHU administrator.',
                410
            );
        }

        // Defense in depth: DB expiry must agree with the signed expiry.
        if ($invite->isExpired()) {
            return $this->failure(
                'expired',
                'This registration link has expired. Please ask the RHU administrator for a new one.',
                410
            );
        }

        // 4 — one-time use: a used link fails cleanly, never silently succeeds.
        if ($invite->isUsed()) {
            return $this->failure(
                'already_used',
                'This registration link has already been used. Each link works exactly once — please ask the RHU administrator for a new one.',
                409
            );
        }

        return [
            'ok' => true,
            'code' => 'valid',
            'message' => 'This registration link is valid.',
            'status' => 200,
            'invite' => $invite,
        ];
    }

    /**
     * Mark the invite as consumed — exactly once, under a row lock, inside the
     * caller's registration transaction. Returns false when another request
     * won the race (the caller must treat that as "already used").
     */
    public function consume(RegistrationInvite $invite, User $user): bool
    {
        $fresh = RegistrationInvite::whereKey($invite->getKey())
            ->lockForUpdate()
            ->first();

        if (!$fresh || $fresh->isUsed() || $fresh->isRevoked() || $fresh->isExpired()) {
            return false;
        }

        $fresh->forceFill([
            'used_at' => Carbon::now(),
            'used_by_user_id' => (int) ($user->user_id ?? $user->getKey()),
        ])->save();

        return true;
    }

    public function revoke(RegistrationInvite $invite): RegistrationInvite
    {
        if (!$invite->isRevoked()) {
            $invite->forceFill(['revoked_at' => Carbon::now()])->save();
        }

        return $invite;
    }

    /**
     * Table-existence guard so deploying this code BEFORE running the
     * migration cannot brick staff registration in production.
     */
    public function isEnabled(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('registration_invites');
    }

    /** Rebuild the canonical signed URL as a Request Laravel can verify. */
    private function rebuiltRequest(string $token, string $expires, string $signature): Request
    {
        // Parameter order MUST match URL::temporarySignedRoute output:
        // ksort'ed params (expires, token) then signature appended last.
        $url = $this->withCanonicalRoot(
            fn () => route(self::VERIFY_ROUTE, [
                'expires' => $expires,
                'token' => $token,
                'signature' => $signature,
            ])
        );

        return Request::create($url);
    }

    /**
     * Generate/verify against the canonical APP_URL root so reverse proxies,
     * differing Host headers, and CLI generation all agree on one signature.
     */
    private function withCanonicalRoot(callable $callback): mixed
    {
        URL::forceRootUrl((string) config('app.url'));

        try {
            return $callback();
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function failure(string $code, string $message, int $status): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'status' => $status,
            'invite' => null,
        ];
    }

    /** Same PH-mobile normalization the registration flow uses (09XXXXXXXXX). */
    private function normalizeMobile(?string $value): ?string
    {
        $mobile = preg_replace('/[^\d+]/', '', (string) $value) ?? '';

        if ($mobile === '') {
            return null;
        }

        if (str_starts_with($mobile, '+63')) {
            $mobile = '0' . substr($mobile, 3);
        } elseif (str_starts_with($mobile, '63') && strlen($mobile) === 12) {
            $mobile = '0' . substr($mobile, 2);
        }

        return $mobile;
    }
}
