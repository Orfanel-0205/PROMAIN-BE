<?php
// database/seeders/UserSeeder.php
//
// Two demo accounts for a developer's own computer: an MHO and a resident.
//
// They used to be created everywhere with a password written right here, in
// a repository that went onto the handover CD. Production's copy of the MHO
// (09000000000) was removed on 9 Nov 2026. Now they are never created in
// production, and each run gives them a new random password, printed once.

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipped demo accounts: never created in production.');

            return;
        }

        $mhoRole = UserRole::where('name', 'mho')->firstOrFail();
        $residentRole = UserRole::where('name', 'resident')->firstOrFail();

        $mhoPassword = Str::password(16);
        $residentPassword = Str::password(16);

        User::updateOrCreate(
            ['mobile_number' => '09000000000'],
            [
                'role_id' => $mhoRole->role_id,
                'first_name' => 'Demo',
                'last_name' => 'MHO',
                'email' => 'mho@ka-agapay.local',
                'password' => Hash::make($mhoPassword),
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
                'password' => Hash::make($residentPassword),
                'account_status' => 'pending',
                'id_verified' => false,
                'barangay' => 'Poblacion',
                'sex' => 'male',
            ]
        );

        $this->command?->info('Demo accounts for this computer only:');
        $this->command?->line("  MHO       09000000000  {$mhoPassword}");
        $this->command?->line("  Resident  09111111111  {$residentPassword}");
    }
}
