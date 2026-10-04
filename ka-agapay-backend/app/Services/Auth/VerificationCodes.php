<?php
// app/Services/Auth/VerificationCodes.php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\VerificationCode;
use App\Services\Notification\AccountMailService;
use App\Services\Notification\AccountSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Six-digit codes sent by SMS to prove the account holder has their phone.
 *
 * THE RULES, AND WHAT EACH ONE IS FOR
 * -----------------------------------
 *   Sent only after the password is right. Every caller checks the password
 *   first, so nobody can make the system send a text by guessing -- the SMS
 *   credit cannot be drained and an account holder's phone cannot be
 *   flooded by someone who does not already know the password.
 *
 *   Five minutes, one use. Long enough to read a text and type it, short
 *   enough that an old code on a lost phone is worthless.
 *
 *   Five wrong codes and the challenge is dead. One in a million per guess,
 *   five guesses: the password has to be entered again to get a new code.
 *
 *   Three sends per challenge, at least sixty seconds apart, and at most five
 *   codes per account per hour and ten per day. A slow network gets a
 *   resend; a script with the password cannot drain the SMS credit.
 *
 *   Only a hash is stored: HMAC-SHA256 of challenge|code with the app key.
 *   A database backup holds nothing that signs anyone in.
 *
 *   Bound to its purpose. A code issued to view an API key cannot complete a
 *   login, and the reverse, because verify() checks the purpose it was
 *   issued for.
 *
 * PASSWORD RESET IS THE EXCEPTION TO "AFTER THE PASSWORD"
 * -------------------------------------------------------
 * Someone who forgot their password has nothing else to prove, so a reset
 * code is sent on request. Three things keep that from being abused:
 *
 *   The same per-account caps (five an hour, ten a day, across every
 *   purpose), and a per-IP daily limit on reset requests in the routes, so
 *   the SMS credit cannot be drained one account at a time.
 *
 *   It also goes by email when the account has a real address, so a reset
 *   nobody asked for is seen in two places.
 *
 *   A request for a number with no account gets a stand-in (issueDecoy):
 *   a stored challenge with no account and no sent code that counts attempts,
 *   resends and waits exactly like a real one. Nothing the reset page says
 *   reveals whether a number belongs to a patient of the RHU.
 */
final class VerificationCodes
{
    public const PURPOSE_ADMIN_LOGIN = 'admin_login';
    public const PURPOSE_RESIDENT_LOGIN = 'resident_login';
    public const PURPOSE_REVEAL_KEY = 'reveal_key';
    public const PURPOSE_ADMIN_RESET = 'admin_password_reset';
    public const PURPOSE_RESIDENT_RESET = 'resident_password_reset';

    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_EMAIL = 'email';

    public const TTL_SECONDS = 300;
    public const MAX_ATTEMPTS = 5;
    public const MAX_SENDS = 3;
    public const RESEND_AFTER_SECONDS = 60;
    public const MAX_CODES_PER_HOUR = 5;

    /**
     * Someone who already has the password could otherwise ask for five codes
     * an hour, every hour: about 120 texts a day from one account, enough to
     * drain the prepaid SMS credit in a week. Ten a day is plenty for an
     * account holder with a bad signal and very little for anyone else.
     */
    public const MAX_CODES_PER_DAY = 10;

    public function __construct(
        private readonly AccountSmsService $sms,
        private readonly AccountMailService $mail,
    ) {}

    /**
     * Create a challenge and send its code to the account holder.
     *
     * By SMS unless other channels are asked for. With several, the code goes
     * to every one the account can receive, and counts as sent if any of them
     * took it.
     *
     * @param array<string, mixed> $context what the code unlocks
     * @param array<int, string> $channels CHANNEL_SMS and/or CHANNEL_EMAIL
     * @return array{status:string, challenge?:string, masked_mobile?:string, masked_email?:?string, channels?:array<int,string>, expires_in?:int, resend_after?:int}
     *   status is one of: sent, no_mobile (nowhere to send it), send_failed, too_many
     */
    public function issue(User $user, string $purpose, array $context, Request $request, array $channels = [self::CHANNEL_SMS]): array
    {
        $available = $this->reachable($user, $channels);

        if ($available === []) {
            return ['status' => 'no_mobile'];
        }

        if ($this->overSendingCap($user)) {
            return ['status' => 'too_many'];
        }

        // Remembered for resends. Left out for SMS-only codes, so their rows
        // are exactly what they were before email existed.
        if ($available !== [self::CHANNEL_SMS]) {
            $context['channels'] = $available;
        }

        // One live challenge per account and purpose: a new one retires the old.
        VerificationCode::query()
            ->where('user_id', $user->user_id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $challenge = Str::random(48);
        $code = self::generateCode();

        $row = VerificationCode::query()->create([
            'challenge'    => $challenge,
            'user_id'      => $user->user_id,
            'purpose'      => $purpose,
            'code_hash'    => self::hashCode($challenge, $code),
            'context'      => $context === [] ? null : $context,
            'expires_at'   => now()->addSeconds(self::TTL_SECONDS),
            'attempts'     => 0,
            'sends'        => 1,
            'last_sent_at' => now(),
            'ip_address'   => $request->ip(),
            'user_agent'   => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        $delivered = $this->deliver($user, $code, $purpose, $available);

        if ($delivered === []) {
            // Nothing was sent, so nothing can be entered: retire it now.
            $row->update(['consumed_at' => now()]);

            return ['status' => 'send_failed'];
        }

        return [
            'status'        => 'sent',
            'challenge'     => $challenge,
            'masked_mobile' => (string) $this->sms->maskedMobile($user),
            'masked_email'  => in_array(self::CHANNEL_EMAIL, $delivered, true) ? $this->mail->maskedEmail($user) : null,
            'channels'      => $delivered,
            'expires_in'    => self::TTL_SECONDS,
            'resend_after'  => self::RESEND_AFTER_SECONDS,
        ];
    }

    /**
     * A challenge for a request that names no account.
     *
     * Stored like a real one, with no account and the hash of a code that is
     * never sent or kept, so nobody can enter it. Wrong-code counts, resends,
     * the wait between them and expiry all behave exactly as for a real
     * challenge. The caller evens out the reply time (a real send waits on
     * Semaphore; this does not): see AuthController::answerNoSoonerThan().
     *
     * @return array{status:string, challenge:string, expires_in:int, resend_after:int}
     */
    public function issueDecoy(string $purpose, Request $request): array
    {
        $challenge = Str::random(48);

        VerificationCode::query()->create([
            'challenge'    => $challenge,
            'user_id'      => null,
            'purpose'      => $purpose,
            'code_hash'    => self::hashCode($challenge, self::generateCode()),
            'context'      => null,
            'expires_at'   => now()->addSeconds(self::TTL_SECONDS),
            'attempts'     => 0,
            'sends'        => 1,
            'last_sent_at' => now(),
            'ip_address'   => $request->ip(),
            'user_agent'   => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        return [
            'status'       => 'sent',
            'challenge'    => $challenge,
            'expires_in'   => self::TTL_SECONDS,
            'resend_after' => self::RESEND_AFTER_SECONDS,
        ];
    }

    /**
     * Check a code.
     *
     * @return array{status:string, user?:User, context?:array<string,mixed>, attempts_left?:int}
     *   status is one of: ok, wrong, expired, unknown
     */
    public function verify(string $challenge, string $purpose, string $code): array
    {
        $row = $this->live($challenge, $purpose);

        if ($row === null) {
            return ['status' => 'unknown'];
        }

        if ($row->expires_at->isPast() || $row->attempts >= self::MAX_ATTEMPTS) {
            $row->update(['consumed_at' => $row->consumed_at ?? now()]);

            return ['status' => 'expired'];
        }

        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== 6 || !hash_equals($row->code_hash, self::hashCode($challenge, $code))) {
            $attempts = $row->attempts + 1;
            $row->update([
                'attempts' => $attempts,
                // The fifth wrong code ends the challenge.
                'consumed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
            ]);

            // The account is returned so the caller can record the attempt: a
            // wrong code after a right password means someone knows the
            // password but does not have the phone.
            return [
                'status' => 'wrong',
                'attempts_left' => max(0, self::MAX_ATTEMPTS - $attempts),
                'user' => $row->user,
            ];
        }

        // Consume before returning, so the same code cannot be replayed.
        $row->update(['consumed_at' => now()]);

        // A stand-in has no account to hand back. Its code was never known,
        // so this cannot happen -- but if it did, it must not count as proof.
        if ($row->user === null) {
            return ['status' => 'unknown'];
        }

        return [
            'status'  => 'ok',
            'user'    => $row->user,
            'context' => $row->context ?? [],
        ];
    }

    /**
     * Send a fresh code for the same challenge.
     *
     * The old code stops working: the hash is replaced, so only the newest
     * text is valid.
     *
     * @return array{status:string, wait?:int, masked_mobile?:string, expires_in?:int}
     *   status is one of: sent, wait, limit, unknown, send_failed
     */
    public function resend(string $challenge, string $purpose): array
    {
        $row = $this->live($challenge, $purpose);

        if ($row === null || $row->expires_at->isPast()) {
            return ['status' => 'unknown'];
        }

        if ($row->sends >= self::MAX_SENDS || ($row->user && $this->overSendingCap($row->user))) {
            return ['status' => 'limit'];
        }

        $wait = self::RESEND_AFTER_SECONDS - (int) $row->last_sent_at?->diffInSeconds(now(), true);

        if ($wait > 0) {
            return ['status' => 'wait', 'wait' => $wait];
        }

        $user = $row->user;
        $isDecoy = $row->user_id === null;

        if ($user === null && !$isDecoy) {
            // The account was deleted since the code was sent.
            return ['status' => 'unknown'];
        }

        $code = self::generateCode();

        $row->update([
            'code_hash'    => self::hashCode($challenge, $code),
            'sends'        => $row->sends + 1,
            'attempts'     => 0,
            'last_sent_at' => now(),
            'expires_at'   => now()->addSeconds(self::TTL_SECONDS),
        ]);

        if ($isDecoy) {
            return ['status' => 'sent', 'masked_mobile' => '', 'expires_in' => self::TTL_SECONDS];
        }

        $channels = $this->reachable($user, (array) ($row->context['channels'] ?? [self::CHANNEL_SMS]));

        if ($this->deliver($user, $code, $row->purpose, $channels) === []) {
            return ['status' => 'send_failed'];
        }

        return [
            'status'        => 'sent',
            'masked_mobile' => (string) $this->sms->maskedMobile($user),
            'expires_in'    => self::TTL_SECONDS,
        ];
    }

    public static function isPasswordReset(string $purpose): bool
    {
        return $purpose === self::PURPOSE_ADMIN_RESET || $purpose === self::PURPOSE_RESIDENT_RESET;
    }

    /** Retire every pending code for an account, e.g. after a password reset. */
    public function retireAll(User $user): void
    {
        if (!Schema::hasTable('verification_codes')) {
            return;
        }

        VerificationCode::query()
            ->where('user_id', $user->user_id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    // ------------------------------------------------------------------

    /** Six digits, uniformly random, leading zeros kept. */
    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The stored form of a code: bound to its challenge and the app key, so a
     * hash cannot be reused for another challenge or reversed offline without
     * the key -- six digits are a million guesses, which is nothing if the hash
     * were a plain SHA-256 of the code.
     */
    public static function hashCode(string $challenge, string $code, ?string $key = null): string
    {
        return hash_hmac('sha256', $challenge . '|' . $code, $key ?? (string) config('app.key'));
    }

    /** Texts sent to this account in the last hour and day, resends included. */
    private function overSendingCap(User $user): bool
    {
        $sent = fn ($since) => (int) VerificationCode::query()
            ->where('user_id', $user->user_id)
            ->where('created_at', '>=', $since)
            ->sum('sends');

        return $sent(now()->subHour()) >= self::MAX_CODES_PER_HOUR
            || $sent(now()->subDay()) >= self::MAX_CODES_PER_DAY;
    }

    private function live(string $challenge, string $purpose): ?VerificationCode
    {
        if ($challenge === '' || strlen($challenge) > 64) {
            return null;
        }

        return VerificationCode::query()
            ->where('challenge', $challenge)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->first();
    }

    /**
     * The requested channels this account can actually be reached on.
     *
     * @param array<int, string> $channels
     * @return array<int, string>
     */
    private function reachable(User $user, array $channels): array
    {
        $reachable = [];

        if (in_array(self::CHANNEL_SMS, $channels, true) && $this->sms->canReceiveCodes($user)) {
            $reachable[] = self::CHANNEL_SMS;
        }

        if (in_array(self::CHANNEL_EMAIL, $channels, true) && $this->mail->canReceive($user)) {
            $reachable[] = self::CHANNEL_EMAIL;
        }

        return $reachable;
    }

    /**
     * Send the code on each channel; return the ones that took it.
     *
     * SMS counts only when Semaphore accepted the message. SmsService marks a
     * send "sent" when Semaphore returns a message id, and records anything
     * else -- a missing key, a refusal -- as "failed" rather than throwing, so
     * the status is the only reliable signal.
     *
     * @param array<int, string> $channels
     * @return array<int, string>
     */
    private function deliver(User $user, string $code, string $purpose, array $channels): array
    {
        $reset = self::isPasswordReset($purpose);
        $delivered = [];

        if (in_array(self::CHANNEL_SMS, $channels, true)) {
            $log = $reset
                ? $this->sms->sendPasswordResetCode($user, $code)
                : $this->sms->sendVerificationCode($user, $code);

            if ($log !== null && (string) $log->status === 'sent') {
                $delivered[] = self::CHANNEL_SMS;
            }
        }

        // Only reset codes go by email so far; every other code is SMS-only.
        if ($reset && in_array(self::CHANNEL_EMAIL, $channels, true) && $this->mail->sendPasswordResetCode($user, $code)) {
            $delivered[] = self::CHANNEL_EMAIL;
        }

        return $delivered;
    }

}
