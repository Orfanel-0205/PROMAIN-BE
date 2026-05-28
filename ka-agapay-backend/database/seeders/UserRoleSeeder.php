<?php
// database/seeders/UserRoleSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'super_admin',
            'rhu_admin',
            'doctor',
            'nurse',
            'midwife',
            'bhw',
            'resident',
            'guardian',
        ];

        foreach ($roles as $name) {
            DB::table('user_roles')->insertOrIgnore([
                'name'       => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('✅ User roles seeded (' . count($roles) . ' roles)');
    }
}