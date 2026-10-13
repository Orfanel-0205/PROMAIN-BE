<?php
// tests/Feature/Security/ApiRateLimitTest.php
//
// How often a signed-in person may call the API (Oct 2026).
//
// The admin dashboard polls notifications, unread counts and the queue on
// their own timers, so one person uses well over sixty requests a minute.
// A `throttle:60,1` on the signed-in route group capped them there, and
// staff browsing normally were refused ("Too Many Attempts"). The limit is
// now the `api` limiter's: 300 a minute per person.

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_busy_minute_on_the_admin_is_not_refused_but_a_flood_is(): void
    {
        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        $nurse = User::create([
            'role_id' => UserRole::where('name', 'nurse')->value('role_id'),
            'first_name' => 'Busy',
            'last_name' => 'Nurse',
            'mobile_number' => '09181234567',
            'password' => bcrypt('password'),
            'account_status' => 'active',
            'assigned_rhu_id' => 1,
        ]);

        // Two minutes' worth of what the dashboard does, inside one minute.
        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($nurse)->getJson('/api/v1/notifications/unread-count')->assertOk();
        }

        // Still limited: past 300 in a minute is refused.
        for ($i = 0; $i < 180; $i++) {
            $this->actingAs($nurse)->getJson('/api/v1/notifications/unread-count');
        }

        $this->actingAs($nurse)->getJson('/api/v1/notifications/unread-count')->assertStatus(429);
    }
}
