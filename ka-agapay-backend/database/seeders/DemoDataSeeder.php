<?php
// database/seeders/DemoDataSeeder.php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use App\Models\Barangay;
use App\Models\ResidentProfile;
use App\Models\QueuePriorityRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Demo Staff Accounts ───────────────────────────────────────────────

        $roles     = UserRole::pluck('role_id', 'name');
        $barangays = Barangay::pluck('barangay_id', 'name');

        $accounts = [
            [
                'first_name'     => 'Admin',
                'last_name'      => 'KaAgapay',
                'mobile_number'  => '09170000001',
                'email'          => 'admin@kaagapay.ph',
                'role'           => 'super_admin',
                'account_status' => 'active',
            ],
            [
                'first_name'     => 'Dr. Maria',
                'last_name'      => 'Santos',
                'mobile_number'  => '09170000002',
                'email'          => 'mho@kaagapay.ph',
                'role'           => 'mho',
                'account_status' => 'active',
            ],
            [
                'first_name'     => 'Jamal',
                'last_name'      => 'Muhammed',
                'mobile_number'  => '09206932142',
                'email'          => 'jamal@kaagapay.ph',
                'role'           => 'staff_admin',
                'account_status' => 'active',
            ],
            [
                'first_name'     => 'Juan',
                'last_name'      => 'dela Cruz',
                'mobile_number'  => '09170000003',
                'email'          => 'staff@kaagapay.ph',
                'role'           => 'staff_admin',
                'account_status' => 'active',
            ],
            [
                'first_name'     => 'Jutsushiki Hanten',
                'last_name'      => 'Aka',
                'mobile_number'  => '09170543213',
                'email'          => 'hanten@kaagapay.ph',
                'role'           => 'staff_admin',
                'account_status' => 'active',
            ],
            [
                'first_name'     => 'Ana',
                'last_name'      => 'Reyes',
                'mobile_number'  => '09170000004',
                'email'          => 'bhw@kaagapay.ph',
                'role'           => 'bhw',
                'account_status' => 'active',
            ],
            [
                'first_name'     => 'Pedro',
                'last_name'      => 'Gonzales',
                'mobile_number'  => '09170000005',
                'email'          => 'resident@kaagapay.ph',
                'role'           => 'resident',
                'account_status' => 'active',
            ],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrCreate(
                ['mobile_number' => $account['mobile_number']],
                [
                    'first_name'     => $account['first_name'],
                    'last_name'      => $account['last_name'],
                    'email'          => $account['email'],
                    'password'       => Hash::make('password123'),
                    'role_id'        => $roles[$account['role']] ?? null,
                    'barangay_id'    => $barangays['Poblacion East'] ?? null,
                    'account_status' => $account['account_status'],
                ]
            );

            // Create resident profile for resident users
            if ($account['role'] === 'resident') {
                ResidentProfile::firstOrCreate(
                    ['user_id' => $user->user_id],
                    [
                        'barangay_id' => $barangays['Poblacion East'] ?? null,
                        'birth_date'  => '1958-03-12', // Age 66 — triggers senior priority
                        'sex'         => 'male',
                        'address'     => '123 Mabini Street, Poblacion East, Malasiqui',
                        'philhealth_no' => '12-345678901-2',
                    ]
                );
            }
        }

        $this->command->info('Demo accounts seeded. All passwords: password123');
        $this->command->table(
            ['Role', 'Mobile', 'Email'],
            collect($accounts)->map(fn($a) => [
                $a['role'], $a['mobile_number'], $a['email']
            ])->toArray()
        );
    }
}
