<?php
// tests/Feature/Time/AnalyticsLocalTimeTest.php
//
// Analytics on the Philippine clock (Oct 2026).
//
// Timestamps are stored in UTC. The reports read them as they came, so a
// ticket issued at 9:00 AM in Manila (01:00 UTC) was charted as a 1 AM
// ticket, the "peak queue hour" was eight hours early all day long, and a
// day's range ran from 8:00 AM to 8:00 AM, putting early-morning patients on
// the previous day's figures.

namespace Tests\Feature\Time;

use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsLocalTimeTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_queue_hours_and_day_totals_are_philippine(): void
    {
        $this->ticket('2026-10-20 23:30:00'); // 7:30 AM on the 21st, Manila
        $this->ticket('2026-10-21 01:00:00'); // 9:00 AM
        $this->ticket('2026-10-21 01:20:00'); // 9:20 AM
        $this->ticket('2026-10-21 16:30:00'); // 12:30 AM on the 22nd: not the 21st

        Carbon::setTestNow('2026-10-21 05:00:00'); // 1:00 PM in Manila

        $admin = $this->superAdmin();
        $range = 'from=2026-10-21&to=2026-10-21';

        $queue = $this->actingAs($admin)
            ->getJson("/api/v1/analytics/queue-performance?{$range}")
            ->assertOk()
            ->json('data');

        $hours = collect($queue['queue_by_hour'])->pluck('count', 'hour')->all();
        $this->assertSame([7 => 1, 9 => 2], $hours, 'tickets charted on the UTC clock');
        $this->assertSame(9, $queue['peak_queue_hour']['hour'] ?? null);

        $overview = $this->actingAs($admin)
            ->getJson("/api/v1/analytics/overview?{$range}")
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $overview['total_queue_tickets'], "the 21st's tickets, 7:30 AM included and past midnight excluded");
    }

    public function test_raw_timestamps_are_sent_with_a_zone(): void
    {
        // A browser reads "2026-10-20 23:30:00" as local time: 11:30 PM on the
        // 20th instead of 7:30 AM on the 21st.
        $this->assertSame('2026-10-20T23:30:00.000000Z', LocalTime::iso('2026-10-20 23:30:00'));
        $this->assertSame('2026-10-21', LocalTime::iso('2026-10-21'));
        $this->assertSame('2026-10-20T23:30:00.000000Z', LocalTime::iso('2026-10-20T23:30:00.000000Z'));
        $this->assertNull(LocalTime::iso(null));
    }

    public function test_a_stored_value_reads_on_the_philippine_clock(): void
    {
        $this->assertSame('2026-10-21 09:00', LocalTime::fromStored('2026-10-21 01:00:00')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21', LocalTime::fromStored('2026-10-21')->toDateString());
        $this->assertTrue(LocalTime::isDateColumn('c.consultation_date'));
        $this->assertFalse(LocalTime::isDateColumn('q.issued_at'));
    }

    private function ticket(string $issuedAtUtc): void
    {
        static $n = 0;

        QueueTicket::create([
            'ticket_number' => sprintf('OPD-%03d', ++$n),
            'resident_profile_id' => $this->resident()->id,
            'rhu_id' => 1,
            'service_type' => 'opd_consultation',
            'priority_score' => 10,
            'priority_category' => 'regular',
            'status' => 'waiting',
            'issued_at' => Carbon::parse($issuedAtUtc),
        ]);
    }

    private function superAdmin(): User
    {
        return User::create([
            'role_id' => UserRole::where('name', 'super_admin')->value('role_id'),
            'first_name' => 'Report',
            'last_name' => 'Admin',
            'mobile_number' => '09184007777',
            'email' => 'report.admin@example.test',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }

    private function resident(): ResidentProfile
    {
        $user = User::create([
            'role_id' => UserRole::firstOrCreate(['name' => 'resident'], ['permissions' => []])->role_id,
            'first_name' => 'Resident',
            'last_name' => 'Hours' . ($this->phone + 1),
            'mobile_number' => sprintf('0918400%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        return ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => \App\Models\Barangay::first()->barangay_id,
            'birth_date' => '1990-01-01',
        ]);
    }
}
