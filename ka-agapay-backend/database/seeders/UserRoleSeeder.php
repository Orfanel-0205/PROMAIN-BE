<?php
// database/seeders/UserRoleSeeder.php

namespace Database\Seeders;

use App\Models\UserRole;
use Illuminate\Database\Seeder;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['resident', 'admin', 'staff', 'bhw', 'super_admin'];

        foreach ($roles as $name) {
            UserRole::firstOrCreate(['name' => $name]);
        }
    }
}