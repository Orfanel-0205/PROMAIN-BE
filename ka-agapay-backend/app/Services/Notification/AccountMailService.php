<?php
// app/Services/Notification/AccountMailService.php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Account email: password reset codes and "your password was reset" notices.
 *
 * The email counterpart of AccountSmsService, and the first email this system
 * sends at all.
 *
 * ITS OWN CONNECTION
 * ------------------
 * Sent through the mailbox in config('services.mail_sender'), normally a
 * Gmail address and app password pasted by a super admin in Settings > API
 * keys, over a connection built for each message. The server's MAIL_MAILER
 * (currently "log", which writes mail to a file and sends nothing) is never
 * used, so turning email on needs no server access -- and with no sender set,
 * email is skipped and codes still go by SMS.
 *
 * WHO IS SENT EMAIL
 * -----------------
 * Only real-looking addresses. Staff accounts were created with placeholder
 * addresses such as ...@kaagapay.local; those, and the reserved example and
 * test domains, are skipped rather than handed to Gmail to bounce.
 *
 * NEVER THROWS
 * ------------
 * A mail failure is logged (without the code) and reported as false, the same
 * way an SMS failure is a "failed" sms_logs row: a reset must not break
 * because Gmail is slow.
 */
class AccountMailService
{
    /** Seconds to wait on Gmail before giving up; SMS still carries the code. */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Domains that never receive mail. .local and .invalid are where the demo
     * staff accounts' addresses live; the rest are reserved by RFC 2606/6761.
     */
    private const PLACEHOLDER_SUFFIXES = ['.local', '.invalid', '.test', '.example', '.localhost', '.internal'];

    private const PLACEHOLDER_DOMAINS = ['example.com', 'example.net', 'example.org', 'localhost'];

    /** Whether a sending mailbox has been set up at all. */
    public function isConfigured(): bool
    {
        return self::deliverableAddress((string) config('services.mail_sender.address', '')) !== null
            && self::appPassword() !== '';
    }

    /** Whether this account can be sent email right now. */
    public function canReceive(User $user): bool
    {
        return $this->isConfigured() && self::deliverableAddress($user->email) !== null;
    }

    /** "j•••@gmail.com", for "we sent a code to ...". */
    public function maskedEmail(User $user): ?string
    {
        $email = self::deliverableAddress($user->email);

        if ($email === null) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    public function sendPasswordResetCode(User $user, string $code): bool
    {
        return $this->send($user, 'Your Ka-Agapay password reset code', [
            'heading' => 'Reset your password',
            'intro' => 'Someone asked to reset the password for your Ka-Agapay account. Enter this code to choose a new password:',
            'code' => $code,
            'lines' => [
                'The code expires in 5 minutes and works once. Never share it -- RHU staff will never ask for it.',
                'Did not ask for this? Ignore this email. Your password has not changed.',
            ],
        ]);
    }

    public function sendPasswordResetNotice(User $user): bool
    {
        return $this->send($user, 'Your Ka-Agapay password was reset', [
            'heading' => 'Your password was reset',
            'intro' => 'The password for your Ka-Agapay account was just reset, and every device that was signed in has been signed out.',
            'code' => null,
            'lines' => [
                'If this was you, there is nothing else to do.',
                'If it was not you, contact your Rural Health Unit right away so they can secure your account.',
            ],
        ]);
    }

    /**
     * A usable address, lowercased, or null for none or a placeholder.
     */
    public static function deliverableAddress(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $domain = substr($email, strrpos($email, '@') + 1);

        if (in_array($domain, self::PLACEHOLDER_DOMAINS, true) || !str_contains($domain, '.')) {
            return null;
        }

        foreach (self::PLACEHOLDER_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return null;
            }
        }

        return $email;
    }

    /**
     * Gmail shows app passwords in four groups of four ("abcd efgh ijkl
     * mnop"), and they are pasted that way. The spaces are not part of it.
     */
    public static function appPassword(?string $value = null): string
    {
        return preg_replace('/\s+/', '', (string) ($value ?? config('services.mail_sender.app_password', ''))) ?? '';
    }

    /**
     * Connection settings for a mailbox, also used by the API keys panel to
     * test an address and app password before saving them.
     *
     * @return array<string, mixed>
     */
    public static function transportConfig(string $address, string $appPassword): array
    {
        $port = (int) config('services.mail_sender.port', 587);

        return [
            'transport' => 'smtp',
            // 587 upgrades to TLS after connecting (STARTTLS); 465 starts encrypted.
            'scheme' => $port === 465 ? 'smtps' : 'smtp',
            'host' => (string) config('services.mail_sender.host', 'smtp.gmail.com'),
            'port' => $port,
            'username' => $address,
            'password' => self::appPassword($appPassword),
            'timeout' => self::TIMEOUT_SECONDS,
        ];
    }

    /** @param array{heading:string, intro:string, code:?string, lines:array<int,string>} $data */
    private function send(User $user, string $subject, array $data): bool
    {
        $to = self::deliverableAddress($user->email);

        if ($to === null || !$this->isConfigured()) {
            return false;
        }

        $from = (string) self::deliverableAddress((string) config('services.mail_sender.address'));
        $fromName = (string) config('services.mail_sender.from_name', 'Ka-Agapay RHU');

        try {
            Mail::build(self::transportConfig($from, self::appPassword()))->send(
                ['html' => 'emails.account', 'text' => 'emails.account-text'],
                $data + ['name' => trim((string) $user->first_name) ?: 'there'],
                function ($message) use ($to, $from, $fromName, $subject, $user) {
                    $message->to($to, trim($user->first_name . ' ' . $user->last_name) ?: null)
                        ->from($from, $fromName)
                        ->subject($subject);
                },
            );

            return true;
        } catch (\Throwable $e) {
            // The message never includes the code: SMTP errors quote the
            // server's reply, not the email body.
            Log::warning('[AccountMailService] Account email could not be sent', [
                'user_id' => $user->user_id ?? null,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
