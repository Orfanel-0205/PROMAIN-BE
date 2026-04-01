<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['resident', 'staff_admin', 'super_admin', 'mho', 'bhw'];

        foreach ($roles as $role) {
            DB::table('user_roles')->insertOrIgnore(['name' => $role]);
        }
    }
}