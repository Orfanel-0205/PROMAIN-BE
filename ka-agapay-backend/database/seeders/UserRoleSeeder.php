<?php
// database/seeders/UserRoleSeeder.php

namespace Database\Seeders;

use App\Models\UserRole;
use Illuminate\Database\Seeder;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'resident' => [],
            'patient' => [],
            'doctor' => ['manage_consultations', 'manage_prescriptions', 'view_patients'],
            'nurse' => ['manage_queue', 'manage_consultations', 'manage_prescriptions', 'manage_inventory', 'view_patients'],
            'midwife' => ['manage_queue', 'manage_consultations', 'view_patients'],
            'bhw' => ['manage_queue', 'view_patients'],
            'staff' => ['view_patients'],
            'staff_admin' => ['manage_announcements', 'manage_events', 'manage_queue', 'manage_consultations', 'manage_inventory', 'view_analytics'],
            'rhu_admin' => ['manage_announcements', 'manage_events', 'manage_queue', 'manage_consultations', 'manage_inventory', 'manage_sms', 'view_analytics'],
            'admin' => ['manage_announcements', 'manage_events', 'manage_queue', 'view_analytics'],
            'mho' => ['full_access', 'manage_users', 'approve_staff'],
            'municipal_mayor' => ['full_access', 'manage_users', 'approve_staff'],
            'it_staff' => ['full_access', 'manage_users', 'approve_staff'],
            'super_admin' => ['full_access', 'manage_users', 'approve_staff'],
        ];

        foreach ($roles as $name => $permissions) {
            UserRole::updateOrCreate(
                ['name' => $name],
                ['permissions' => $permissions]
            );
        }

        $this->command->info('✅ User roles seeded successfully.');
    }
}