<?php
// tests/Feature/Rhu/RhuFacilityTest.php
//
// The system must not assume Malasiqui will always have exactly two RHUs.
//
// Before this, "RHU 1" and "RHU 2" were ids fixed in PHP, and six tables
// declared rhu_id as a foreign key to BARANGAYS, so a third facility was
// impossible without a developer and a migration. These tests hold the line:
// a super admin can open RHU 3 from the dashboard, it immediately becomes a
// valid facility everywhere, and barangays can be moved to it so residents of
// those barangays are served by it.

namespace Tests\Feature\Rhu;

use App\Models\Barangay;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Rhu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RhuFacilityTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $nurse;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        Rhu::flushCache();

        $this->superAdmin = $this->makeUser('super_admin');
        $this->nurse = $this->makeUser('nurse');
    }

    public function test_the_two_existing_facilities_are_records_now(): void
    {
        $this->assertSame([1, 2], Rhu::ids());
        $this->assertSame('RHU 1', Rhu::rhuLabel(1));

        $this->actingAs($this->nurse)
            ->getJson('/api/v1/rhus')
            ->assertOk()
            ->assertJsonPath('data.0.short_name', 'RHU 1')
            ->assertJsonPath('data.1.short_name', 'RHU 2');
    }

    public function test_a_super_admin_can_open_a_third_rhu_and_it_counts_everywhere(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rhus', [
                'code' => 'RHU3',
                'name' => 'RHU 3 Malasiqui (San Julian)',
                'short_name' => 'RHU 3',
                'address' => 'San Julian, Malasiqui, Pangasinan',
            ])
            ->assertCreated();

        $newId = (int) $response->json('data.id');

        $this->assertGreaterThan(2, $newId, 'A new facility must not reuse an existing id.');

        // The helper every scoped query relies on now accepts it.
        $this->assertContains($newId, Rhu::ids());
        $this->assertSame($newId, Rhu::normalizeRhuId($newId));
        $this->assertSame('RHU 3', Rhu::rhuLabel($newId));
    }

    public function test_only_a_super_admin_may_open_or_change_a_facility(): void
    {
        $this->actingAs($this->nurse)
            ->postJson('/api/v1/rhus', [
                'code' => 'RHU9',
                'name' => 'Unauthorised RHU',
                'short_name' => 'RHU 9',
            ])
            ->assertForbidden();

        $this->actingAs($this->nurse)
            ->putJson('/api/v1/rhus/1', ['short_name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame([1, 2], Rhu::ids());
    }

    public function test_barangays_move_to_the_new_facility_and_residents_follow(): void
    {
        $newId = (int) $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rhus', [
                'code' => 'RHU3',
                'name' => 'RHU 3 Malasiqui',
                'short_name' => 'RHU 3',
            ])
            ->json('data.id');

        $barangay = Barangay::orderBy('barangay_id')->skip(3)->first();

        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/rhus/{$newId}/barangays", [
                'barangay_ids' => [$barangay->barangay_id],
            ])
            ->assertOk();

        $this->assertSame(
            $newId,
            (int) DB::table('barangays')->where('barangay_id', $barangay->barangay_id)->value('rhu_id')
        );

        // A resident of that barangay is now served by the new facility.
        $resident = $this->makeUser('resident');
        ResidentProfile::create([
            'user_id' => $resident->user_id,
            'barangay_id' => $barangay->barangay_id,
            'birth_date' => '1994-03-03',
        ]);

        $this->assertSame($newId, Rhu::resolveRhuIdFromUser($resident->fresh()));
    }

    public function test_a_facility_is_switched_off_rather_than_deleted_and_never_the_last_one(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/rhus/2', ['is_active' => false])
            ->assertOk();

        // Gone from the pickers, but its records still make sense.
        $this->assertSame([1], Rhu::ids());
        $this->assertDatabaseHas('rhus', ['id' => 2, 'is_active' => false]);

        $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/rhus/1', ['is_active' => false])
            ->assertStatus(422);

        $this->assertSame([1], Rhu::ids());
    }

    public function test_facility_codes_stay_unique(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/rhus', [
                'code' => 'RHU1',
                'name' => 'Duplicate',
                'short_name' => 'Dup',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    private function makeUser(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0917400%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
