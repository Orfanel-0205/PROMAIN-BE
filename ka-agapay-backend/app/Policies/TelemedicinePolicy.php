<?php
// app/Policies/TelemedicinePolicy.php

namespace App\Policies;

use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\User;

class TelemedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['staff_admin', 'mho', 'super_admin', 'bhw']);
    }

    public function view(User $user, TelemedicineRequest|TelemedicineSession $model): bool
    {
        if ($user->hasAnyRole(['staff_admin', 'mho', 'super_admin', 'bhw'])) {
            return true;
        }

        // Doctor can see their own sessions
        if ($model instanceof TelemedicineSession) {
            return $model->assigned_doctor_id === $user->user_id;
        }

        // Resident can see their own requests
        return $user->residentProfile?->id === $model->resident_profile_id;
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