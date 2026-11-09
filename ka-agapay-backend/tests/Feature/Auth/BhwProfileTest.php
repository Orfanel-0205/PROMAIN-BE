<?php
// tests/Feature/Auth/BhwProfileTest.php
//
// BHWs work in the web admin (consultations, events, the queue, follow-ups),
// so My Profile and the first-sign-in tour flag must answer them too. Both
// were refused with 403 until Nov 2026; the tour flag's refusal showed a
// "Forbidden" screen the first time a BHW opened the dashboard.

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BhwProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bhw_can_open_my_profile_and_dismiss_the_tour(): void
    {
        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        $bhw = User::create([
            'role_id' => UserRole::firstOrCreate(['name' => 'bhw'], ['permissions' => []])->role_id,
            'first_name' => 'Barangay',
            'last_name' => 'Worker',
            'mobile_number' => '09178000001',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        $this->actingAs($bhw)->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.role', 'bhw');
        $this->actingAs($bhw)->postJson('/api/v1/admin/profile/onboarding-seen')->assertSuccessful();
        $this->actingAs($bhw)->getJson('/api/v1/admin/profile/signature')->assertForbidden();
    }
}
