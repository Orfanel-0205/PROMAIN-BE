<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserRole;
use App\Models\Barangay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);
    }

    public function test_user_can_register_as_resident(): void
    {
        $barangay = Barangay::first();

        $payload = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'johndoe@example.com',
            'mobile_number' => '09171112222',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'barangay_id' => $barangay->barangay_id,
            // FINAL RULE: residents must accept Terms; account is created pending.
            'terms_accepted' => true,
        ];

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => ['user_id', 'first_name', 'last_name', 'mobile_number', 'role', 'barangay'],
                'token'
            ]);

        $this->assertDatabaseHas('users', [
            'mobile_number' => '09171112222',
            'email' => 'johndoe@example.com',
            'first_name' => 'John',
        ]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $residentRole = UserRole::where('name', 'resident')->first();
        
        $user = User::create([
            'role_id'        => $residentRole->role_id,
            'first_name'     => 'Jane',
            'last_name'      => 'Doe',
            'mobile_number'  => '09181234567',
            'password'       => bcrypt('securepass'),
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'mobile_number' => '09181234567',
            'password' => 'securepass',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user',
                'token'
            ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $residentRole = UserRole::where('name', 'resident')->first();
        
        $user = User::create([
            'role_id'        => $residentRole->role_id,
            'first_name'     => 'Jane',
            'last_name'      => 'Doe',
            'mobile_number'  => '09181234567',
            'password'       => bcrypt('securepass'),
            'account_status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'mobile_number' => '09181234567',
            'password' => 'securepass',
        ]);

        // FINAL RULE: pending accounts are blocked at login and told to wait
        // for Super Admin approval.
        $response->assertStatus(403)
            ->assertJson(['message' => 'Your account is pending Super Admin approval.']);
    }
}
