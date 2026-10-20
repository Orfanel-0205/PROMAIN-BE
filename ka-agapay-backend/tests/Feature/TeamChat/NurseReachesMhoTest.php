<?php
// tests/Feature/TeamChat/NurseReachesMhoTest.php
//
// Clinical staff can work the queue and reach the MHO (Oct 2026).
//
// Both were found by running the whole patient day on the live system as a
// nurse:
//
//   - POST /queue/issue kept its own list of roles without doctor, nurse or
//     midwife, so adding a walk-in from the Queue page said "This action is
//     unauthorized". The queue policy, which the rest of the queue follows,
//     always allowed them.
//   - Team chat let the MHO start a chat with any nurse, but no nurse could
//     start one with the MHO or find the MHO in Contacts: the MHO has no RHU
//     of their own, so "same RHU" never held.

namespace Tests\Feature\TeamChat;

use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NurseReachesMhoTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);
    }

    public function test_a_nurse_can_add_a_walk_in_to_the_queue(): void
    {
        $nurse = $this->user('nurse', 1);
        $profile = ResidentProfile::create([
            'user_id' => $this->user('resident', null)->user_id,
            'barangay_id' => DB::table('barangays')->min('barangay_id'),
            'birth_date' => '1990-01-01',
        ]);
        $rhuId = \App\Support\Rhu::defaultId();
        if (!DB::table('barangays')->where('barangay_id', $rhuId)->exists()) {
            DB::table('barangays')->insert(['barangay_id' => $rhuId, 'name' => 'Test Facility Barangay', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($nurse)
            ->postJson('/api/v1/queue/issue', [
                'resident_profile_id' => $profile->id,
                'rhu_id' => $rhuId,
                'service_type' => 'opd_consultation',
            ])
            ->assertSuccessful();
    }

    public function test_a_nurse_finds_and_messages_the_mho(): void
    {
        $nurse = $this->user('nurse', 1);
        $mho = $this->user('mho', null);

        $contacts = collect($this->actingAs($nurse)->getJson('/api/v1/team-chat/contacts')->assertOk()->json('data'))
            ->pluck('id')
            ->all();
        $this->assertContains($mho->user_id, $contacts, 'the MHO is missing from a nurse\'s contacts');

        $this->actingAs($nurse)
            ->postJson('/api/v1/team-chat/conversations', ['type' => 'dm', 'target_id' => $mho->user_id])
            ->assertSuccessful();
    }

    public function test_nurses_of_different_rhus_still_cannot_message_each_other(): void
    {
        $this->actingAs($this->user('nurse', 1))
            ->postJson('/api/v1/team-chat/conversations', ['type' => 'dm', 'target_id' => $this->user('nurse', 2)->user_id])
            ->assertForbidden();
    }

    private function user(string $role, ?int $rhu): User
    {
        return User::create([
            'role_id' => UserRole::firstOrCreate(['name' => $role], ['permissions' => []])->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Reach' . ($this->phone + 1),
            'mobile_number' => sprintf('0918500%04d', ++$this->phone),
            'email' => $role === 'resident' ? null : "reach{$this->phone}@example.test",
            'password' => bcrypt('password'),
            'account_status' => 'active',
            'assigned_rhu_id' => $rhu,
        ]);
    }
}
