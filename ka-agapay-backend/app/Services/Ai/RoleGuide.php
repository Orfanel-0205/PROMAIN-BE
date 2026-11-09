<?php
// app/Services/Ai/RoleGuide.php
//
// WHAT EACH STAFF ROLE MAY DO, for the staff assistant (Nov 2026).
//
// The assistant used to answer every staff member the same way: a BHW asking
// how to issue an e-prescription got the steps for a screen the server would
// refuse them, and a nurse asking for the analytics got told where to click.
// The answer is now shaped by who is asking: the screens their role opens,
// the actions that belong to someone else and who that is, and examples from
// their own work.
//
// THIS MIRRORS THE SERVER'S OWN RULES -- route middleware in routes/api.php,
// PrescriptionController::PRESCRIBER_ROLES, QueueTicketPolicy,
// InventoryController::authorizeInventory, and the admin's lib/roleAccess.ts.
// When one of those changes, change this too: tests/Feature/Assistant/
// RoleAwareAssistantTest.php checks the important ones against the routes.

namespace App\Services\Ai;

use App\Models\User;

final class RoleGuide
{
    /**
     * Answer-book screen => roles that can open it, each list copied from the
     * rule that guards it. Screens not listed are open to every staff role.
     *
     * @return array<string, array<int, string>>
     */
    public static function screenRoles(): array
    {
        // QueueTicketPolicy::hasStaffAccess, FollowUpReminderController::STAFF_ROLES
        $staff = ['admin', 'staff', 'staff_admin', 'rhu_admin', 'mho', 'super_admin', 'doctor', 'nurse', 'midwife', 'bhw'];
        // routes: admin appointments, patient registry, reports
        $clinicDesk = ['admin', 'staff', 'rhu_admin', 'super_admin', 'mho', 'doctor', 'nurse', 'midwife'];
        // routes: consultations list
        $consultations = [...$clinicDesk, 'bhw'];
        // routes: analytics, events editor, announcements
        $cms = ['admin', 'staff', 'rhu_admin', 'super_admin', 'mho'];

        return [
            'queue' => $staff,
            'appointments' => $clinicDesk,
            'consultations' => $consultations,
            'speech_to_text' => $consultations,
            'prescriptions' => $staff,
            'patients' => $clinicDesk,
            'registrations' => ['super_admin', 'superadmin', 'mho', 'mho_admin'],
            // InventoryController::authorizeInventory
            'inventory' => ['mho', 'super_admin', 'staff_admin', 'admin', 'rhu_admin', 'staff', 'doctor', 'nurse', 'midwife'],
            'followups' => $staff,
            'telemedicine' => $staff,
            'events' => ['admin', 'staff', 'staff_admin', 'rhu_admin', 'super_admin', 'mho', 'doctor', 'nurse', 'midwife', 'bhw'],
            'reports' => $clinicDesk,
            'analytics' => $cms,
            'users' => ['admin', 'staff_admin', 'rhu_admin', 'mho', 'municipal_mayor', 'it_staff', 'super_admin', 'superadmin'],
            'rhus' => ['super_admin', 'superadmin'],
            'teamchat' => ['doctor', 'nurse', 'midwife', 'bhw', 'head_nurse', 'rhu_staff', 'staff', 'staff_admin', 'rhu_admin', 'admin', 'mho', 'it_staff', 'super_admin'],

            // Pages the assistant can open that the answer book has no entry for.
            'cms' => $cms,
            'settings' => ['super_admin', 'mho', 'rhu_admin'],
            'sms' => ['super_admin', 'mho', 'rhu_admin'],
        ];
    }

    /** Screen titles for pages outside the answer book. */
    private const EXTRA_TITLES = [
        'cms' => 'Announcements and Events editor',
        'settings' => 'Settings',
        'sms' => 'SMS',
    ];

    public static function title(string $screen): string
    {
        return AnswerBook::titles()[$screen] ?? self::EXTRA_TITLES[$screen] ?? ucfirst($screen);
    }

    /** The assistant's "open this page" actions (ChatController) => the screen behind each. */
    public const ACTION_SCREENS = [
        'open_queue' => 'queue',
        'open_appointments' => 'appointments',
        'open_consultations' => 'consultations',
        'open_prescriptions' => 'prescriptions',
        'open_patient_registry' => 'patients',
        'open_registrations' => 'registrations',
        'open_inventory' => 'inventory',
        'open_followups' => 'followups',
        'open_telemedicine' => 'telemedicine',
        'open_events' => 'events',
        'open_reports' => 'reports',
        'open_analytics' => 'analytics',
        'open_heatmap' => 'analytics',
        'open_users' => 'users',
        'open_team_chat' => 'teamchat',
        'open_cms' => 'cms',
        'open_settings' => 'settings',
        'open_sms' => 'sms',
    ];

    /** Whether the assistant may open this page for this staff member. */
    public static function allowsAction(?User $user, ?string $action): bool
    {
        $screen = $action !== null ? (self::ACTION_SCREENS[$action] ?? null) : null;

        return $screen === null || self::canOpen(self::roleOf($user), $screen);
    }

    /**
     * Things inside a screen that only some roles may do.
     *
     * @return array<string, array{label: string, roles: array<int, string>}>
     */
    public static function actions(): array
    {
        return [
            // PrescriptionController::PRESCRIBER_ROLES
            'issue_prescription' => ['label' => 'issuing an e-prescription or lab request', 'roles' => ['doctor', 'mho', 'mho_admin', 'super_admin', 'superadmin']],
            // routes: PATCH admin/consultations/{id}/complete
            'complete_consultation' => ['label' => 'completing a consultation (the diagnosis and drugs)', 'roles' => ['doctor', 'mho', 'mho_admin', 'super_admin']],
            // StaffSignatures::SIGNING_ROLES
            'sign_documents' => ['label' => 'saving a signature for prescriptions and ITRs (My Profile)', 'roles' => ['doctor', 'mho']],
            'edit_events' => ['label' => 'creating, editing or publishing events and announcements', 'roles' => ['admin', 'staff', 'rhu_admin', 'super_admin', 'mho']],
            'adjust_stock' => ['label' => 'adjusting inventory stock counts', 'roles' => ['mho', 'super_admin']],
            'send_sms' => ['label' => 'sending SMS blasts and checking SMS credits', 'roles' => ['super_admin', 'mho', 'rhu_admin']],
            'settings' => ['label' => 'changing Settings and API keys', 'roles' => ['super_admin', 'mho', 'rhu_admin']],
            'approve_staff' => ['label' => 'approving staff registrations', 'roles' => ['super_admin', 'superadmin', 'mho', 'mho_admin']],
        ];
    }

    /** Which assistant data lookup needs which screen (AssistantTools). */
    public const TOOL_SCREENS = [
        'queue_status' => 'queue',
        'appointment_counts' => 'appointments',
        'low_stock_medicines' => 'inventory',
        'prescription_counts' => 'prescriptions',
        'followup_counts' => 'followups',
    ];

    private const LABELS = [
        'mho' => 'MHO (Municipal Health Officer)',
        'mho_admin' => 'MHO (Municipal Health Officer)',
        'doctor' => 'Doctor',
        'nurse' => 'Nurse',
        'midwife' => 'Midwife',
        'bhw' => 'Barangay Health Worker (BHW)',
        'admin' => 'RHU Admin',
        'staff' => 'RHU Staff',
        'staff_admin' => 'RHU Staff Admin',
        'rhu_admin' => 'RHU Admin',
        'super_admin' => 'Super Admin',
        'superadmin' => 'Super Admin',
        'it_staff' => 'IT Staff',
        'municipal_mayor' => 'Municipal Mayor',
        'head_nurse' => 'Head Nurse',
        'rhu_staff' => 'RHU Staff',
    ];

    private const FOCUS = [
        'mho' => 'overseeing every RHU: approving staff, completing records, issuing prescriptions, analytics and the heatmap, SMS and settings',
        'mho_admin' => 'overseeing every RHU: approving staff, completing records, issuing prescriptions, analytics and the heatmap, SMS and settings',
        'doctor' => 'consultations and the SOAP note, completing records, e-prescriptions and lab requests, telemedicine calls',
        'nurse' => 'the queue and walk-ins, vital signs and the first part of the SOAP, telemedicine screening, releasing medicines, follow-ups',
        'midwife' => 'prenatal and postnatal visits, family planning and immunization consultations, follow-ups',
        'bhw' => 'barangay events and their walk-ins, follow-ups in the barangay, their part of the SOAP, sending patients to the RHU',
        'admin' => 'the front desk: queue, appointments, events and announcements, reports and users',
        'staff' => 'the front desk: queue, appointments, events and announcements, reports',
        'staff_admin' => 'the front desk and staff accounts',
        'rhu_admin' => 'running the RHU: queue, appointments, events, reports, users, SMS and settings',
        'super_admin' => 'the whole system: RHU facilities, users and roles, settings and API keys',
        'superadmin' => 'the whole system: RHU facilities, users and roles, settings and API keys',
    ];

    public static function roleOf(?User $user): string
    {
        return strtolower((string) ($user?->role?->name ?? ''));
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? ucwords(str_replace('_', ' ', $role ?: 'staff'));
    }

    /** Whether the role opens an answer-book screen. Unknown screens are open. */
    public static function canOpen(string $role, string $screen): bool
    {
        $roles = self::screenRoles()[$screen] ?? null;

        return $roles === null || in_array($role, $roles, true);
    }

    public static function canUseTool(?User $user, string $tool): bool
    {
        $screen = self::TOOL_SCREENS[$tool] ?? null;

        return $screen === null || self::canOpen(self::roleOf($user), $screen);
    }

    /** "Doctor, MHO (Municipal Health Officer) or Super Admin". */
    public static function whoCan(array $roles): string
    {
        $labels = array_values(array_unique(array_map([self::class, 'label'], $roles)));

        if (count($labels) <= 1) {
            return $labels[0] ?? 'another role';
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' or ' . $last;
    }

    /**
     * The block that tells the model who it is talking to. Empty for a user
     * with no role, so nothing changes for them.
     */
    public static function promptFor(?User $user): string
    {
        $role = self::roleOf($user);

        if ($role === '' || $role === 'resident') {
            return '';
        }

        $titles = AnswerBook::titles();
        $open = [];
        $closed = [];

        foreach ($titles as $screen => $title) {
            if (self::canOpen($role, $screen)) {
                $open[] = $title;
            } else {
                $closed[] = $title . ' (' . self::whoCan(self::screenRoles()[$screen]) . ')';
            }
        }

        $notTheirs = [];

        foreach (self::actions() as $action) {
            if (!in_array($role, $action['roles'], true)) {
                $notTheirs[] = $action['label'] . ' (' . self::whoCan($action['roles']) . ')';
            }
        }

        $lines = [
            'WHO YOU ARE TALKING TO: a ' . self::label($role) . '.',
            'Screens their role can open: ' . implode(', ', $open) . '.',
        ];

        if ($closed !== []) {
            $lines[] = 'Screens their role CANNOT open, with who can: ' . implode('; ', $closed) . '.';
        }

        if ($notTheirs !== []) {
            $lines[] = 'Actions that belong to other roles: ' . implode('; ', $notTheirs) . '.';
        }

        $lines[] = 'Never give steps for a screen or an action their role cannot use: say plainly that it belongs to another role, '
            . 'name who can do it, and offer the part they can do themselves. Do not suggest ways around the access rules.';

        if (isset(self::FOCUS[$role])) {
            $lines[] = 'Their daily work is ' . self::FOCUS[$role] . '; pick examples from it.';
        }

        return "\n\n" . implode("\n", $lines);
    }

    /** The reference for a screen the asker cannot open, instead of its steps. */
    public static function closedScreenBrief(string $role, string $screen, string $title): string
    {
        return "KA-AGAPAY REFERENCE: the {$title} screen is NOT available to this staff member's role ("
            . self::label($role) . '). It is used by ' . self::whoCan(self::screenRoles()[$screen] ?? []) . '. '
            . 'Do not give its steps. Say who can do it, and what this person can do instead.';
    }

    /** The same, as a plain answer when the model is unavailable. */
    public static function closedScreenAnswer(string $role, string $screen, string $title): string
    {
        return "{$title} is not available to your role (" . self::label($role) . '). '
            . 'It is used by ' . self::whoCan(self::screenRoles()[$screen] ?? []) . '. '
            . 'Ask one of them, or ask your RHU admin if your work needs this access.';
    }
}
