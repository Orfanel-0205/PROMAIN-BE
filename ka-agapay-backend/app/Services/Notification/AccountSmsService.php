<?php
// app/Services/Notification/AccountSmsService.php
//
// Account-lifecycle SMS (Part 3). Reuses the existing Semaphore integration via
// SmsService::send(), which ALSO writes an sms_logs row — so every message here
// is visible in the SMS Center exactly like a manual send. Never throws: an SMS
// failure must never break account creation or the approval workflow.
//
// Design note: we deliberately use plain sign-in instructions (not a one-time
// signed login link). A signed auto-login token would need new backend surface
// AND mobile deep-link handling; that is documented as a future improvement and
// intentionally deferred for final-defense stability. Residents sign in with
// their mobile number + password (see AuthController::login).

namespace App\Services\Notification;

use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AccountSmsService
{
    /**
     * The sign-in code text. Under 160 characters with the code in, so it costs
     * one credit, and silent about what the code unlocks -- a code sent to a
     * wrong or recycled number is read by a stranger. Both are held by a test.
     */
    public const VERIFICATION_CODE_MESSAGE = 'Ka-Agapay code: %s. It expires in 5 minutes. Never share it; RHU staff will never ask for it. Not you? Change your password.';

    public function __construct(private readonly SmsService $sms)
    {
    }

    /**
     * 3a — staff-assisted account creation. Tells the resident their account is
     * ready, their username (mobile number), and how to sign in. Includes the
     * temporary password ONLY when the system generated a default one (staff
     * left it blank); when staff set a password, they convey it in person.
     */
    public function sendWelcome(User $user, ?string $temporaryPassword = null): ?SmsLog
    {
        $mobile = $this->recipientMobile($user);
        if ($mobile === null) {
            return null;
        }

        $first = $this->firstName($user);

        $parts = [
            "Hi {$first}, your Ka-Agapay account is ready.",
            "Username: {$mobile}.",
        ];

        if ($temporaryPassword !== null && $temporaryPassword !== '') {
            $parts[] = "Temporary password: {$temporaryPassword}. Please change it after your first sign-in.";
        } else {
            $parts[] = "Please sign in using the password given to you by RHU staff.";
        }

        $parts[] = "Open the Ka-Agapay app to sign in.";

        return $this->dispatch($user, $mobile, implode(' ', $parts), 'account_welcome');
    }

    /**
     * Security transparency: an administrator changed this account's password.
     *
     * The NEW PASSWORD IS DELIBERATELY NOT SENT. SMS is an insecure, logged
     * channel (every message lands in sms_logs and stays on the handset), and
     * the admin conveys the password in person. The point of this message is so
     * a staff member finds out if their account was taken over.
     */
    public function sendPasswordChangedByAdmin(User $user): ?SmsLog
    {
        $mobile = $this->recipientMobile($user);
        if ($mobile === null) {
            return null;
        }

        $first = $this->firstName($user);

        $message = implode(' ', [
            "Hi {$first}, your Ka-Agapay password was changed by an administrator on "
                . now()->format('d M Y, g:i A') . '.',
            'If you did not expect this, contact your RHU administrator immediately.',
        ]);

        return $this->dispatch($user, $mobile, $message, 'account_password_changed');
    }

    /**
     * 3b — self-registration approved. Reused for staff approvals too (harmless
     * and consistent).
     */
    public function sendRegistrationApproved(User $user): ?SmsLog
    {
        $mobile = $this->recipientMobile($user);
        if ($mobile === null) {
            return null;
        }

        $first = $this->firstName($user);

        $message = "Hi {$first}, good news! Your Ka-Agapay registration has been approved. "
            . "You may now sign in with your mobile number ({$mobile}) in the Ka-Agapay app.";

        return $this->dispatch($user, $mobile, $message, 'registration_approved');
    }

    /**
     * Registration received — account pending review. Sent right after a
     * successful self-registration so the applicant knows the submission worked
     * and does not need to re-register or visit the RHU to ask.
     */
    public function sendRegistrationPending(User $user): ?SmsLog
    {
        $mobile = $this->recipientMobile($user);
        if ($mobile === null) {
            return null;
        }

        $first = $this->firstName($user);

        $message = "Hi {$first}, we received your Ka-Agapay registration. "
            . "Your account is pending review by the Super Admin — please wait for approval. "
            . "We will text you once it is decided. No need to register again.";

        return $this->dispatch($user, $mobile, $message, 'registration_pending');
    }

    /**
     * 3b — self-registration rejected. Keeps the reason brief and non-stigmatizing
     * and always tells the resident the concrete next step (resubmit a clearer ID).
     */
    public function sendRegistrationRejected(User $user, ?string $reason = null): ?SmsLog
    {
        $mobile = $this->recipientMobile($user);
        if ($mobile === null) {
            return null;
        }

        $first = $this->firstName($user);
        $shortReason = Str::limit(
            trim((string) preg_replace('/\s+/', ' ', (string) $reason)) ?: 'your ID could not be verified',
            90
        );

        $message = "Hi {$first}, your Ka-Agapay registration was not approved this time. "
            . "Reason: {$shortReason}. Please resubmit a clearer photo of your valid ID in the app to try again.";

        return $this->dispatch($user, $mobile, $message, 'registration_rejected');
    }

    private function dispatch(User $user, string $mobile, string $message, string $type): ?SmsLog
    {
        try {
            return $this->sms->send($mobile, $message, $type, (int) ($user->user_id ?? $user->getKey()));
        } catch (\Throwable $e) {
            Log::warning('[AccountSmsService] SMS dispatch failed', [
                'type'    => $type,
                'user_id' => $user->user_id ?? null,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Return the resident's mobile in the app's canonical 09XXXXXXXXX format, or
     * null when there is no valid PH mobile to send to (skip silently).
     */
    private function recipientMobile(User $user): ?string
    {
        $mobile = preg_replace('/\D/', '', (string) $user->mobile_number) ?? '';

        if (preg_match('/^09\d{9}$/', $mobile) === 1) {
            return $mobile;
        }

        // Accept 639XXXXXXXXX / 63... stored variants and normalize back to 09.
        if (preg_match('/^63(9\d{9})$/', $mobile, $m) === 1) {
            return '0' . $m[1];
        }

        return null;
    }

    private function firstName(User $user): string
    {
        $first = trim((string) $user->first_name);

        return $first !== '' ? $first : 'there';
    }

    /**
     * A one-time sign-in or verification code.
     *
     * Kept under 160 characters so it costs one credit. It names nothing the
     * code unlocks -- not "API key", not "admin" -- because a code sent to a
     * wrong or recycled number is read by a stranger, and the text should
     * tell that stranger nothing about the system.
     *
     * The code is redacted from the sms_logs row once it has been handed to
     * Semaphore. Every message lands in that table and shows in the SMS
     * Center, so an unredacted row would let anyone with SMS Center access
     * read a live code.
     *
     * Returns null when the account has no valid Philippine mobile number.
     * A returned row may still have status "failed" -- SmsService records a
     * failed send rather than throwing -- so callers check the status.
     */
    public function sendVerificationCode(User $user, string $code): ?SmsLog
    {
        $mobile = $this->recipientMobile($user);

        if ($mobile === null) {
            return null;
        }

        $message = sprintf(self::VERIFICATION_CODE_MESSAGE, $code);

        $log = $this->dispatch($user, $mobile, $message, 'verification_code');

        if ($log !== null) {
            try {
                $log->update(['message' => str_replace($code, '******', (string) $log->message)]);
            } catch (\Throwable $e) {
                Log::warning('[AccountSmsService] Could not redact a verification code from sms_logs', [
                    'sms_log_id' => $log->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $log;
    }

    /**
     * Tell the PREVIOUS number that the account's number changed.
     *
     * Sent to the old number on purpose: if someone else changed it, the new
     * number is theirs, and the real owner is the one who must hear about it.
     */
    public function sendMobileChangedNotice(User $user, string $oldMobile): ?SmsLog
    {
        $digits = preg_replace('/\D/', '', $oldMobile) ?? '';

        if (preg_match('/^63(9\d{9})$/', $digits, $m) === 1) {
            $digits = '0' . $m[1];
        }

        if (preg_match('/^09\d{9}$/', $digits) !== 1) {
            return null;
        }

        $message = 'Ka-Agapay: the mobile number on your account was just changed. Not you? Contact your RHU right away.';

        return $this->dispatch($user, $digits, $message, 'mobile_changed');
    }

    /** Whether this account has a mobile number a code can be sent to. */
    public function canReceiveCodes(User $user): bool
    {
        return $this->recipientMobile($user) !== null;
    }

    /** Only the last three digits, for "we sent a code to the number ending 001". */
    public function maskedMobile(User $user): ?string
    {
        $mobile = $this->recipientMobile($user);

        return $mobile === null ? null : substr($mobile, -3);
    }
}
