<?php
// app/Policies/TelemedicinePolicy.php

namespace App\Policies;

use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\User;

class TelemedicinePolicy
{
    /**
     * RHU staff who operate the telemedicine desk and may open a session.
     *
     * This is the UNION of the two role lists routes/api.php already uses for
     * telemedicine — the Level 1 screening roles and the Level 2 clinical roles
     * — plus the roles this policy already trusted.
     *
     * It is deliberately this wide. Before the session endpoint was authorized
     * at all, the web admin's "Start Video" / "Open Room" controls were gated by
     * session STATUS and not by role, so any staff member could already open a
     * room. Narrowing that in the same change would have logged screening nurses
     * out of a workflow they use today.
     *
     * The confidentiality boundary this closes is the one that actually leaked:
     * a resident reading another resident's consultation and minting a video
     * token for it. Whether a screening nurse should be able to enter a doctor's
     * consultation at all is a clinical-policy question for the RHU, not a bug
     * fix — see docs/OPERATIONS.md.
     */
    private const SESSION_STAFF_ROLES = [
        'staff_admin', 'mho', 'super_admin', 'bhw',
        'doctor', 'nurse', 'midwife', 'head_nurse',
        'staff', 'rhu_staff', 'rhu_admin',
    ];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['staff_admin', 'mho', 'super_admin', 'bhw']);
    }

    public function view(User $user, TelemedicineRequest|TelemedicineSession $model): bool
    {
        if ($user->hasAnyRole(['staff_admin', 'mho', 'super_admin', 'bhw'])) {
            return true;
        }

        if ($model instanceof TelemedicineSession) {
            return $user->hasAnyRole(self::SESSION_STAFF_ROLES)
                || $this->isSessionParticipant($user, $model);
        }

        // Resident can see their own requests
        return $user->residentProfile?->id === $model->resident_profile_id;
    }

    /**
     * Who is genuinely part of a telemedicine consultation.
     *
     * This deliberately mirrors the scoping SessionController::index() already
     * applies to the list endpoint. Before this existed, `view` recognised only
     * the assigned doctor, so authorizing the single-session endpoint would have
     * locked out the patient it belongs to — which is why that endpoint had no
     * authorization at all and handed a freshly minted JaaS token to any
     * authenticated caller.
     *
     * Membership is: the assigned doctor, the BHW companion (barangay-assisted
     * flow), the resident the consultation is for, and whoever submitted the
     * request on their behalf (guardian-assisted bookings).
     */
    private function isSessionParticipant(User $user, TelemedicineSession $session): bool
    {
        if ($session->assigned_doctor_id === $user->user_id) {
            return true;
        }

        if ($session->bhw_companion_id !== null && $session->bhw_companion_id === $user->user_id) {
            return true;
        }

        $request = $session->relationLoaded('request')
            ? $session->request
            : $session->loadMissing('request')->request;

        if (!$request) {
            return false;
        }

        if ($request->requested_by === $user->user_id) {
            return true;
        }

        $profileId = $user->residentProfile?->id;

        return $profileId !== null && $profileId === $request->resident_profile_id;
    }

    public function screen(User $user, TelemedicineRequest $request): bool
    {
        return $user->hasAnyRole(['staff_admin', 'mho', 'super_admin']);
    }

    public function cancel(User $user, TelemedicineRequest $request): bool
    {
        // Admin can cancel any. Resident can only cancel their own.
        if ($user->hasAnyRole(['staff_admin', 'mho', 'super_admin'])) return true;
        return $user->residentProfile?->id === $request->resident_profile_id;
    }

    public function createSession(User $user, TelemedicineRequest $request): bool
    {
        return $user->hasAnyRole(['staff_admin', 'mho', 'super_admin']);
    }

    public function updateStatus(User $user, TelemedicineSession $session): bool
    {
        // Assigned doctor or admin can control session flow
        return $user->hasAnyRole(['mho', 'super_admin', 'staff_admin'])
            || $session->assigned_doctor_id === $user->user_id;
    }

    public function saveNotes(User $user, TelemedicineSession $session): bool
    {
        return $user->hasAnyRole(['mho', 'super_admin'])
            || $session->assigned_doctor_id === $user->user_id;
    }

    public function createReferral(User $user, TelemedicineSession $session): bool
    {
        return $user->hasAnyRole(['mho', 'super_admin'])
            || $session->assigned_doctor_id === $user->user_id;
    }

    public function viewSummary(User $user): bool
    {
        return $user->hasAnyRole(['mho', 'super_admin', 'staff_admin']);
    }
}