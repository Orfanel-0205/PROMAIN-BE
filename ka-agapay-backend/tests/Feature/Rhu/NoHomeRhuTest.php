<?php
// tests/Feature/Rhu/NoHomeRhuTest.php
//
// A resident has no RHU of their own (Oct 2026).
//
// Every RHU serves the whole of Malasiqui. Residents used to be given the RHU
// of their barangay -- RHU 2 for every barangay -- and that "home RHU" hid
// them from RHU 1's patient registry, overrode the RHU they picked for a
// queue ticket, and filed a follow-up under "RHU 21" (a barangay id). Now:
//
//   * staff at any RHU can find and open any resident;
//   * a record is filed under the RHU where the visit happened;
//   * a resident's "RHU" is the one they last went to, or none.

namespace Tests\Feature\Rhu;

use App\Models\Barangay;
use App\Models\FollowUpReminder;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Rhu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NoHomeRhuTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private User $nurseRhu1;
    private User $nurseRhu2;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        Rhu::flushCache();

        // Their barangay is mapped to RHU 2, as every barangay is today.
        $barangay = Barangay::orderBy('barangay_id')->skip(5)->first();
        DB::table('barangays')->where('barangay_id', $barangay->barangay_id)->update(['rhu_id' => 2]);

        $this->resident = $this->makeUser('resident');
        ResidentProfile::create([
            'user_id' => $this->resident->user_id,
            'barangay_id' => $barangay->barangay_id,
            'birth_date' => '1990-05-05',
        ]);

        $this->nurseRhu1 = $this->makeUser('nurse', 1);
        $this->nurseRhu2 = $this->makeUser('nurse', 2);
    }

    public function test_a_resident_has_no_rhu_from_their_barangay(): void
    {
        $this->assertNull(Rhu::resolveRhuIdFromUser($this->resident->fresh()));

        // Staff keep theirs.
        $this->assertSame(1, Rhu::resolveRhuIdFromUser($this->nurseRhu1));
        $this->assertSame(2, Rhu::resolveRhuIdFromUser($this->nurseRhu2));
    }

    public function test_staff_at_any_rhu_can_find_and_open_any_resident(): void
    {
        foreach ([$this->nurseRhu1, $this->nurseRhu2] as $nurse) {
            $this->actingAs($nurse)
                ->getJson('/api/v1/patients/registry')
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.user_id', $this->resident->user_id);

            $this->actingAs($nurse)
                ->getJson("/api/v1/patients/{$this->resident->user_id}/profile")
                ->assertOk();
        }
    }

    public function test_a_residents_rhu_is_the_one_they_last_went_to(): void
    {
        $this->actingAs($this->resident)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.rhu_id', null);

        $this->consultation(rhuId: 2, at: now()->subDays(10));
        $this->consultation(rhuId: 1, at: now()->subDay());

        $this->assertSame(1, Rhu::lastVisitedRhuId($this->resident->user_id));

        $this->actingAs($this->resident)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.rhu_id', 1)
            ->assertJsonPath('data.rhu_label', 'RHU 1');

        $this->actingAs($this->nurseRhu2)
            ->getJson('/api/v1/patients/registry')
            ->assertJsonPath('data.0.rhu_label', 'RHU 1');
    }

    public function test_a_resident_gets_a_queue_ticket_at_the_rhu_they_chose(): void
    {
        // Their barangay says RHU 2; they chose RHU 1.
        $this->assertSame(1, Rhu::scopeRhuId($this->resident->fresh(), 1));
        $this->assertSame(2, Rhu::scopeRhuId($this->resident->fresh(), 2));

        // Staff stay locked to their own, whatever they send.
        $this->assertSame(1, Rhu::scopeRhuId($this->nurseRhu1, 2));
    }

    public function test_a_follow_up_is_filed_under_the_rhu_of_its_consultation(): void
    {
        $consultationId = $this->consultation(rhuId: 1, at: now());

        // Saved by an RHU 2 nurse, for a visit at RHU 1.
        $this->actingAs($this->nurseRhu2)
            ->postJson('/api/v1/follow-up-reminders', [
                'consultation_id' => $consultationId,
                'needs_follow_up' => true,
                'follow_up_type' => 'single',
                'follow_up_date' => now()->addWeek()->toDateString(),
                'reason' => 'BP recheck',
                'sms_enabled' => false,
            ])
            ->assertSuccessful();

        $this->assertSame(1, (int) FollowUpReminder::where('consultation_id', $consultationId)->value('rhu_id'));
    }

    public function test_records_left_under_a_barangay_id_are_refiled_under_a_real_rhu(): void
    {
        $consultationId = $this->consultation(rhuId: 2, at: now()->subDay());
        $profileId = $this->resident->residentProfile()->value('id');

        // As production had them: under "RHU 21", which is barangay Buto.
        $linked = $this->reminder(['consultation_id' => $consultationId, 'resident_profile_id' => $profileId]);
        $orphan = $this->reminder(['resident_profile_id' => $profileId]);

        DB::table('follow_up_reminders')->whereIn('id', [$linked, $orphan])->update(['rhu_id' => 21]);

        $migration = require database_path('migrations/2026_10_12_000000_refile_records_under_a_real_rhu.php');
        $migration->up();

        $this->assertSame(2, (int) DB::table('follow_up_reminders')->where('id', $linked)->value('rhu_id'));
        $this->assertSame(Rhu::defaultId(), (int) DB::table('follow_up_reminders')->where('id', $orphan)->value('rhu_id'));
    }

    private function consultation(int $rhuId, $at): int
    {
        return (int) DB::table('consultations')->insertGetId([
            'user_id' => $this->resident->user_id,
            'rhu_id' => $rhuId,
            'status' => 'completed',
            'consultation_date' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function reminder(array $overrides): int
    {
        return FollowUpReminder::create($overrides + [
            'user_id' => $this->resident->user_id,
            'rhu_id' => 1,
            'created_by' => $this->nurseRhu1->user_id,
            'patient_name' => 'Resident Tester',
            'mobile_number' => '09170000001',
            'follow_up_at' => now()->addDays(3),
            'follow_up_type' => 'single',
            'follow_up_date' => now()->addDays(3)->toDateString(),
            'reason' => 'BP recheck',
            'status' => 'scheduled',
            'sms_enabled' => false,
        ])->id;
    }

    private function makeUser(string $role, ?int $rhuId = null): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0917500%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
            'assigned_rhu_id' => $rhuId,
        ]);
    }
}
