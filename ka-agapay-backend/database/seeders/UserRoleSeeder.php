<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['resident', 'doctor', 'nurse', 'midwife', 'admin', 'super_admin', 'it_staff', 'mho_admin'];

        foreach ($roles as $role) {
            DB::table('user_roles')->updateOrInsert(
                ['name' => $role],
                ['name' => $role]
            );
        }
    }
}