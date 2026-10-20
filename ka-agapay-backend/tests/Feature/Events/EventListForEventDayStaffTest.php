<?php
// tests/Feature/Events/EventListForEventDayStaffTest.php
//
// The staff running an event can find it (Oct 2026).
//
// Nurses, midwives, doctors and BHWs may open an event's registrants, mark
// attendance, add walk-ins and read the report -- they run event day. The
// list that leads to those pages sat with the CMS routes, so they could do
// all of it except reach it: the Events page was refused. They may now read
// the list; posting and editing stay with the CMS roles.

namespace Tests\Feature\Events;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventListForEventDayStaffTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(\Database\Seeders\UserRoleSeeder::class);
    }

    public function test_event_day_staff_can_read_the_list_but_not_post(): void
    {
        foreach (['nurse', 'midwife', 'doctor', 'bhw'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->getJson('/api/v1/admin/events')->assertOk();
            $this->actingAs($user)
                ->postJson('/api/v1/admin/events', ['title' => 'Not mine to post', 'event_type' => 'event'])
                ->assertForbidden();
        }
    }

    public function test_a_resident_still_cannot_read_the_admin_list(): void
    {
        $this->actingAs($this->user('resident'))->getJson('/api/v1/admin/events')->assertForbidden();
    }

    private function user(string $role): User
    {
        return User::create([
            'role_id' => UserRole::firstOrCreate(['name' => $role], ['permissions' => []])->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'EventDay' . ($this->phone + 1),
            'mobile_number' => sprintf('0918600%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
            'assigned_rhu_id' => $role === 'resident' ? null : 1,
        ]);
    }
}
