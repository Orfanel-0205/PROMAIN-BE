<?php
// app/Policies/QueueTicketPolicy.php

namespace App\Policies;

use App\Models\QueueTicket;
use App\Models\User;

class QueueTicketPolicy
{
    private function hasStaffAccess(?User $user): bool
    {
        return $user?->hasAnyRole([
            'admin',
            'staff',
            'staff_admin',
            'rhu_admin',
            'mho',
            'super_admin',
            'doctor',
            'nurse',
            'midwife',
            'bhw',
        ]) ?? false;
    }

    public function viewAny(User $user): bool
    {
        return $this->hasStaffAccess($user);
    }

    public function view(User $user, QueueTicket $ticket): bool
    {
        if ($this->hasStaffAccess($user)) {
            return true;
        }

        $residentProfile = $user->residentProfile ?? null;

        return $residentProfile && (int) $residentProfile->id === (int) $ticket->resident_profile_id;
    }

    public function create(User $user): bool
    {
        return $this->hasStaffAccess($user) || $user->hasAnyRole(['resident']);
    }

    public function update(User $user, QueueTicket $ticket): bool
    {
        return $this->hasStaffAccess($user);
    }

    public function updateStatus(User $user, QueueTicket $ticket): bool
    {
        return $this->hasStaffAccess($user);
    }

    public function delete(User $user, QueueTicket $ticket): bool
    {
        return $user->hasAnyRole(['admin', 'staff_admin', 'rhu_admin', 'mho', 'super_admin']);
    }

    public function callNext(User $user): bool
    {
        return $this->hasStaffAccess($user);
    }

    public function viewSummary(User $user): bool
    {
        return $this->hasStaffAccess($user);
    }
}