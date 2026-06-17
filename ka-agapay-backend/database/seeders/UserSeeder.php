<?php
// database/seeders/UserSeeder.php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $mhoRole = UserRole::where('name', 'mho')->firstOrFail();
        $residentRole = UserRole::where('name', 'resident')->firstOrFail();

        User::updateOrCreate(
            ['mobile_number' => '09000000000'],
            [
                'role_id' => $mhoRole->role_id,
                'first_name' => 'Demo',
                'last_name' => 'MHO',
                'email' => 'mho@ka-agapay.local',
                'password' => Hash::make('Admin@1234!'),
                'account_status' => 'active',
                'id_verified' => true,
                'barangay' => 'Poblacion',
                'staff_approved_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['mobile_number' => '09111111111'],
            [
                'role_id' => $residentRole->role_id,
                'first_name' => 'Demo',
                'last_name' => 'Resident',
                'email' => 'resident@ka-agapay.local',
                'password' => Hash::make('Resident@1234!'),
                'account_status' => 'pending',
                'id_verified' => false,
                'barangay' => 'Poblacion',
                'sex' => 'male',
            ]
        );

        $this->command->info('✅ Default MHO and resident users seeded.');
    }
}