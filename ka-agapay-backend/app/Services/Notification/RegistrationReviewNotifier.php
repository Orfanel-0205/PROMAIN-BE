<?php
// app/Services/Notification/RegistrationReviewNotifier.php
//
// Super-admin "new registration to review" alerts (panelist feature).
//
// This class only COMPOSES the existing NotificationService public API — it
// adds no new pipeline and does not modify NotificationService internals. The
// staff invite-registration path keeps its own (older) notifyApprovers helper
// in AdminRegistrationController; this notifier covers the two paths that had
// no approver alert: resident self-registration (mobile) and admin-panel
// account creation that lands in 'pending'.
//
// Design decisions (documented for the defense):
// - Channel is the persisted in-app notification only (web-admin bell +
//   Notifications page). No SMS/push per registration: AccountSmsService is
//   the RESIDENT-facing lifecycle sender and there is no admin-facing SMS
//   pattern in this codebase; at municipal scale a registration drive would
//   otherwise text the Super Admin hundreds of times a day.
// - Recipients are resolved from the roles table at call time (active users
//   holding super_admin/superadmin) — never hardcoded IDs. The Super Admin is
//   a system-wide role in this codebase (RHU 1/2 isolation applies to
//   operational data, not to this role), so all active holders are notified;
//   the metadata carries barangay so they can triage per facility.
// - Callers MUST invoke this after their own DB transaction has committed.
//   Everything here is wrapped in try/catch: a notification failure is logged
//   and can never roll back or 500 the registrant's request.

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RegistrationReviewNotifier
{
    private const SUPER_ROLES = ['super_admin', 'superadmin'];

    /** Mobile resident self-registration (AuthController::register). */
    public function newResidentRegistration(User $applicant): void
    {
        $this->notifySuperAdmins(
            $applicant,
            'New resident registration to review',
            $this->applicantName($applicant)
                . ' registered from the mobile app'
                . ($applicant->barangay ? " (Brgy. {$applicant->barangay})" : '')
                . ' and is awaiting approval after ID verification.',
            'resident'
        );
    }

    /**
     * Admin-panel account creation (AdminUserController::store) that lands in
     * 'pending'. $creator lets callers skip self-notification when the person
     * creating the account already holds the super-admin role.
     */
    public function newEmployeeAccountPending(User $applicant, ?User $creator = null): void
    {
        if ($creator !== null && $creator->hasAnyRole(self::SUPER_ROLES)) {
            // The reviewer created this account themselves — a bell alert
            // about their own action is pure noise.
            return;
        }

        $this->notifySuperAdmins(
            $applicant,
            'New employee account to review',
            $this->applicantName($applicant)
                . ' was added from the admin panel and is awaiting approval.',
            'employee'
        );
    }

    private function notifySuperAdmins(
        User $applicant,
        string $title,
        string $message,
        string $applicantKind
    ): void {
        try {
            $approvers = User::query()
                ->whereHas('role', function ($q) {
                    $q->whereIn(DB::raw('LOWER(name)'), self::SUPER_ROLES);
                })
                ->when(
                    Schema::hasColumn('users', 'account_status'),
                    fn ($q) => $q->where('account_status', 'active')
                )
                ->get();

            if ($approvers->isEmpty()) {
                return;
            }

            $notifier = app(NotificationService::class);

            foreach ($approvers as $approver) {
                // Same notification type the staff invite path already uses, so
                // the bell / Notifications page treats all three sources alike;
                // applicant_kind + title carry the resident-vs-employee label.
                $notifier->notifyUser(
                    $approver,
                    'registration_pending_review',
                    $title,
                    $message,
                    [
                        'related_type'   => 'registration',
                        'related_id'     => $applicant->user_id,
                        'applicant_kind' => $applicantKind,
                        'barangay'       => $applicant->barangay ?? null,
                        'screen'         => 'registrations',
                    ],
                    '/registrations'
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[RegistrationReviewNotifier] super-admin notify failed.', [
                'user_id' => $applicant->user_id ?? null,
                'kind'    => $applicantKind,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function applicantName(User $applicant): string
    {
        return trim(implode(' ', array_filter([
            $applicant->first_name,
            $applicant->last_name,
        ]))) ?: ('User #' . $applicant->user_id);
    }
}
