<?php
// database/seeders/UserSeeder.php
// Creates a default super_admin + a demo resident for local dev/testing.
// CHANGE PASSWORDS before deploying to production.

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = UserRole::where('name', 'super_admin')->firstOrFail();
        $resident   = UserRole::where('name', 'resident')->firstOrFail();

        User::firstOrCreate(
            ['mobile_number' => '09000000000'],
            [
                'role_id'        => $superAdmin->role_id,
                'first_name'     => 'Super',
                'last_name'      => 'Admin',
                'email'          => 'admin@ka-agapay.local',
                'password'       => Hash::make('Admin@1234!'),
                'account_status' => 'active',
                'barangay'       => 'Poblacion',
            ]
        );

        User::firstOrCreate(
            ['mobile_number' => '09111111111'],
            [
                'role_id'        => $resident->role_id,
                'first_name'     => 'Demo',
                'last_name'      => 'Resident',
                'email'          => 'resident@ka-agapay.local',
                'password'       => Hash::make('Resident@1234!'),
                'account_status' => 'active',
                'barangay'       => 'Poblacion',
                'sex'            => 'male',
            ]
        );

        $this->command->info('✅ Default users seeded');
    }
}