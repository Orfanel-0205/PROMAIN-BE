<?php
// tests/Feature/Queue/QueuePositionTest.php
//
// What a waiting patient can see.
//
// Until now: their ticket number, and then a push when it was finally their
// turn. Between those two moments they knew nothing, which is why people stand
// in the corridor instead of sitting down, and why they ask staff rather than
// look at a phone.
//
// The number has to match what the desk will actually do, or it is worse than
// nothing. The queue calls by priority first and by ticket order within the
// same priority, so a senior arriving later really does go ahead — and a
// patient told "you are third" who is then called fifth stops believing the
// screen. These tests hold the count to the same rule the desk calls by.

namespace Tests\Feature\Queue;

use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueuePositionTest extends TestCase
{
    use RefreshDatabase;

    private int $rhuId;
    private int $phone = 0;
    private int $tickets = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $this->rhuId = (int) DB::table('barangays')->min('barangay_id');
    }

    public function test_a_patient_is_told_how_many_are_ahead_of_them(): void
    {
        $this->makeTicket($this->makeResident(), 'waiting');
        $this->makeTicket($this->makeResident(), 'waiting');

        $me = $this->makeResident();
        $this->makeTicket($me, 'waiting');

        $position = $this->myTicket($me)['position'];

        $this->assertSame(2, $position['people_ahead']);
        $this->assertSame(3, $position['place_in_line']);
        $this->assertFalse($position['is_next']);
    }

    public function test_the_first_person_waiting_is_told_they_are_next(): void
    {
        $me = $this->makeResident();
        $this->makeTicket($me, 'waiting');

        $position = $this->myTicket($me)['position'];

        $this->assertSame(0, $position['people_ahead']);
        $this->assertTrue($position['is_next']);
    }

    public function test_a_priority_patient_who_arrives_later_is_counted_ahead(): void
    {
        // The desk really does call them first, so the number a patient reads
        // has to say so. Otherwise somebody is told "you are next", watches a
        // later arrival go in, and concludes the system is lying.
        $me = $this->makeResident();
        $this->makeTicket($me, 'waiting');

        $this->makeTicket($this->makeResident(), 'waiting', 80);

        $position = $this->myTicket($me)['position'];

        $this->assertSame(1, $position['people_ahead']);
    }

    public function test_people_already_seen_are_not_counted_as_ahead(): void
    {
        $this->makeTicket($this->makeResident(), 'completed');
        $this->makeTicket($this->makeResident(), 'no_show');

        $me = $this->makeResident();
        $this->makeTicket($me, 'waiting');

        $this->assertSame(0, $this->myTicket($me)['position']['people_ahead']);
    }

    public function test_no_estimate_is_given_when_the_desk_has_served_nobody(): void
    {
        // A confident "0 minutes" on a desk with no history is a promise the
        // RHU cannot keep, and a patient who leaves on the strength of it comes
        // back late and is marked a no-show.
        $me = $this->makeResident();
        $this->makeTicket($me, 'waiting');

        $position = $this->myTicket($me)['position'];

        $this->assertNull($position['average_wait_minutes']);
        $this->assertNull($position['estimated_minutes']);
    }

    public function test_the_estimate_uses_how_long_this_desk_is_actually_taking(): void
    {
        $served = $this->makeTicket($this->makeResident(), 'completed');

        $served->forceFill([
            'called_at' => now()->subMinutes(30),
            'service_ended_at' => now()->subMinutes(20),
        ])->save();

        $this->makeTicket($this->makeResident(), 'waiting');

        $me = $this->makeResident();
        $this->makeTicket($me, 'waiting');

        $position = $this->myTicket($me)['position'];

        $this->assertSame(10, $position['average_wait_minutes']);
        $this->assertSame(10, $position['estimated_minutes']); // one person ahead
    }

    /** @return array<string, mixed> */
    private function myTicket(ResidentProfile $resident): array
    {
        return $this->actingAs($resident->user, 'sanctum')
            ->getJson('/api/v1/queue/my-ticket')
            ->assertOk()
            ->json();
    }

    private function makeTicket(ResidentProfile $patient, string $status, int $priority = 0): QueueTicket
    {
        return QueueTicket::create([
            'ticket_number' => 'Q' . str_pad((string) ++$this->tickets, 4, '0', STR_PAD_LEFT),
            'resident_profile_id' => $patient->id,
            'rhu_id' => $this->rhuId,
            'service_type' => 'opd_consultation',
            'status' => $status,
            'priority_score' => $priority,
            'issued_at' => now(),
        ]);
    }

    private function makeResident(): ResidentProfile
    {
        $roleRow = UserRole::firstOrCreate(['name' => 'resident'], ['permissions' => []]);

        $user = User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => 'Resident',
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0918000%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        $profile = ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => $this->rhuId,
        ]);

        $profile->setRelation('user', $user);

        return $profile;
    }
}
