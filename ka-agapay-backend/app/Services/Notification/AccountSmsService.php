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
            . "Your account is pending review by the RHU — please wait for approval. "
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
}
