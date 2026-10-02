<?php
// app/Services/Auth/VerificationCodes.php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\VerificationCode;
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
 */
final class VerificationCodes
{
    public const PURPOSE_ADMIN_LOGIN = 'admin_login';
    public const PURPOSE_RESIDENT_LOGIN = 'resident_login';
    public const PURPOSE_REVEAL_KEY = 'reveal_key';

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

    public function __construct(private readonly AccountSmsService $sms) {}

    /**
     * Create a challenge and text its code to the account holder.
     *
     * @param array<string, mixed> $context what the code unlocks
     * @return array{status:string, challenge?:string, masked_mobile?:string, expires_in?:int, resend_after?:int}
     *   status is one of: sent, no_mobile, send_failed, too_many
     */
    public function issue(User $user, string $purpose, array $context, Request $request): array
    {
        if (!$this->sms->canReceiveCodes($user)) {
            return ['status' => 'no_mobile'];
        }

        if ($this->overSendingCap($user)) {
            return ['status' => 'too_many'];
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

        if (!$this->deliver($user, $code)) {
            // Nothing was sent, so nothing can be entered: retire it now.
            $row->update(['consumed_at' => now()]);

            return ['status' => 'send_failed'];
        }

        return [
            'status'        => 'sent',
            'challenge'     => $challenge,
            'masked_mobile' => (string) $this->sms->maskedMobile($user),
            'expires_in'    => self::TTL_SECONDS,
            'resend_after'  => self::RESEND_AFTER_SECONDS,
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

        if ($user === null) {
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

        if (!$this->deliver($user, $code)) {
            return ['status' => 'send_failed'];
        }

        return [
            'status'        => 'sent',
            'masked_mobile' => (string) $this->sms->maskedMobile($user),
            'expires_in'    => self::TTL_SECONDS,
        ];
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
     * True only when Semaphore accepted the message.
     *
     * SmsService marks a send "sent" when Semaphore returns a message id, and
     * records anything else -- a missing key, a refusal -- as "failed" rather
     * than throwing, so the status is the only reliable signal.
     */
    private function deliver(User $user, string $code): bool
    {
        $log = $this->sms->sendVerificationCode($user, $code);

        return $log !== null && (string) $log->status === 'sent';
    }
}
