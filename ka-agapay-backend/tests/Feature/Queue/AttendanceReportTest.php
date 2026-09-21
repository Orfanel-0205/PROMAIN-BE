<?php
// tests/Feature/Queue/AttendanceReportTest.php
//
// How many people the RHU actually saw.
//
// This is the figure the municipality asks for and staff have been counting by
// hand. Two things about it are easy to get wrong and hard to notice:
//
//   VISITS and ATTENDEES are different questions. One patient coming three
//   times in a month is three visits and one attendee. Reporting only visits
//   overstates how many people were reached; reporting only attendees hides
//   the workload. Both are given, and these tests hold them apart.
//
//   Attendance means SERVED, not "took a number". Someone who queued and went
//   home was not attended to, and counting them would flatter the figures in
//   the one direction nobody ever questions.

namespace Tests\Feature\Queue;

use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    /**
     * queue_tickets.rhu_id is a foreign key to BARANGAYS, not to the rhus
     * table, which has caught this project out before. A literal 1 happens
     * to work only while barangay 1 exists.
     */
    private int $rhuId;
    private int $phone = 0;
    private int $tickets = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $this->nurse = $this->makeUser('nurse');

        // Ask the endpoint which facility it scopes this nurse to, rather
        // than working it out here. The rule lives in Rhu::scopeRhuId and
        // guessing at it from the test is how this file's tickets ended up
        // filed under a facility the report was never going to look at.
        $this->rhuId = (int) ($this->attendance()['rhu_id'] ?? 1);

        // queue_tickets.rhu_id is a foreign key to BARANGAYS, so that id has
        // to exist there as well.
        if (!DB::table('barangays')->where('barangay_id', $this->rhuId)->exists()) {
            DB::table('barangays')->insert([
                'barangay_id' => $this->rhuId,
                'name' => 'Test Facility Barangay',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_it_counts_people_served_not_tickets_taken(): void
    {
        $patient = $this->makeResident();

        $this->makeTicket($patient, 'completed', today());
        $this->makeTicket($patient, 'completed', today());

        // Took a number and went home. Not attended to.
        $this->makeTicket($this->makeResident(), 'no_show', today());
        $this->makeTicket($this->makeResident(), 'waiting', today());

        $totals = $this->attendance()['totals'];

        $this->assertSame(4, $totals['issued']);
        $this->assertSame(2, $totals['visits']);
        $this->assertSame(1, $totals['no_show']);
        $this->assertSame(1, $totals['still_open']);
    }

    public function test_one_person_seen_twice_is_two_visits_and_one_attendee(): void
    {
        $regular = $this->makeResident();

        $this->makeTicket($regular, 'completed', today());
        $this->makeTicket($regular, 'completed', today());
        $this->makeTicket($this->makeResident(), 'completed', today());

        $totals = $this->attendance()['totals'];

        // Three appointments kept, two different people through the door.
        $this->assertSame(3, $totals['visits']);
        $this->assertSame(2, $totals['attendees']);
    }

    public function test_a_range_of_days_is_broken_down_by_day(): void
    {
        $this->makeTicket($this->makeResident(), 'completed', today()->subDays(2));
        $this->makeTicket($this->makeResident(), 'completed', today()->subDays(2));
        $this->makeTicket($this->makeResident(), 'completed', today());

        $report = $this->attendance([
            'from' => today()->subDays(3)->toDateString(),
            'to' => today()->toDateString(),
        ]);

        $this->assertSame(3, $report['totals']['visits']);
        $this->assertCount(2, $report['by_day']);

        $byDay = collect($report['by_day'])->pluck('visits', 'date');

        $this->assertSame(2, $byDay[today()->subDays(2)->toDateString()]);
        $this->assertSame(1, $byDay[today()->toDateString()]);
    }

    public function test_yesterday_is_not_counted_as_today(): void
    {
        // The whole reason this report exists is that untidy queue data made
        // today's figures wrong; a date filter that leaks is the same bug.
        $this->makeTicket($this->makeResident(), 'completed', today()->subDay());

        $this->assertSame(0, $this->attendance()['totals']['visits']);
    }

    public function test_staff_who_may_not_see_the_queue_are_refused(): void
    {
        $outsider = $this->makeUser('resident');

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/v1/queue/attendance')
            ->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function attendance(array $params = []): array
    {
        return $this->actingAs($this->nurse, 'sanctum')
            ->getJson('/api/v1/queue/attendance?' . http_build_query($params))
            ->assertOk()
            ->json('data');
    }

    private function makeTicket(ResidentProfile $patient, string $status, $issuedAt): void
    {
        DB::table('queue_tickets')->insert([
            'rhu_id' => $this->rhuId,
            'resident_profile_id' => $patient->id,
            'ticket_number' => 'T' . str_pad((string) ++$this->tickets, 4, '0', STR_PAD_LEFT),
            'service_type' => 'opd_consultation',
            'status' => $status,
            'issued_at' => $issuedAt->copy()->setTime(9, 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeResident(): ResidentProfile
    {
        $user = $this->makeUser('resident');

        // A resident profile carries no name of its own: the name lives on
        // the user row. The model's $fillable lists first_name and friends
        // for other shapes of data, and following it here produced a column
        // that does not exist.
        return ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => $this->rhuId,
        ]);
    }

    private function makeUser(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0917800%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
