<?php
// database/seeders/DatabaseSeeder.php
// FIX: UserRoleSeeder MUST run before UserSeeder — the users table
//      has a NOT NULL FK (role_id) so inserting a user without roles
//      seeded first causes a 500 "Default role not found" error.

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ── Step 1: lookup / reference tables ──────────────────────
            UserRoleSeeder::class,   // MUST be first — users.role_id FK
            BarangaySeeder::class,   // MUST be before users if barangay_id FK used

            // ── Step 2: users ───────────────────────────────────────────
            UserSeeder::class,       // creates super_admin + demo accounts
        ]);
    }
}