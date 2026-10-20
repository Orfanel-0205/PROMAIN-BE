<?php
// tests/Feature/Time/PhilippineDayTest.php
//
// "Today" is the Philippine date (Oct 2026).
//
// The application clock is UTC, and Laravel's today() is the UTC date. From
// midnight to 8:00 AM in Manila that is still yesterday. A resident who booked
// at 4:20 AM for the same day saw the booking saved, and the Active board
// showed nothing until 8:00. A ticket taken at 7:30 AM went on the previous
// day's counter, dropped out of "today's queue" at 8:00, and the 00:10 UTC
// sweep (8:10 AM in Manila) closed it as a no-show while the patient waited.
//
// Every test here runs at a Philippine early-morning moment, the window where
// the UTC date and the Philippine date disagree.

namespace Tests\Feature\Time;

use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Prescription;
use App\Models\QueueCounter;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Queue\QueueService;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhilippineDayTest extends TestCase
{
    use RefreshDatabase;

    /** 7:30 AM on 21 Oct 2026 in Manila; still 20 Oct in UTC. */
    private const EARLY_MORNING_UTC = '2026-10-20 23:30:00';

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

    public function test_today_is_the_philippine_date_before_8am(): void
    {
        Carbon::setTestNow(self::EARLY_MORNING_UTC);

        $this->assertSame('2026-10-20', today()->toDateString(), 'precondition: UTC is still the 20th');
        $this->assertSame('2026-10-21', LocalTime::todayDate());

        [$start, $end] = LocalTime::dayRange();
        $this->assertSame('2026-10-20 16:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 16:00:00', $end->format('Y-m-d H:i:s'));

        $this->assertSame('2026-10-21', LocalTime::dateOf(Carbon::parse(self::EARLY_MORNING_UTC)));
    }

    public function test_a_booking_for_today_is_on_the_active_board_before_8am(): void
    {
        Carbon::setTestNow('2026-10-20 20:20:22'); // 4:20 AM in Manila, as reported

        $booking = $this->appointment('2026-10-21', 'pending');

        $ids = collect($this->actingAs($this->staff())
            ->getJson('/api/v1/admin/appointments?board=active')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($booking->id, $ids, "today's booking was hidden until 8:00 AM");
    }

    public function test_an_early_ticket_is_on_todays_counter_and_stays_in_todays_queue(): void
    {
        Carbon::setTestNow(self::EARLY_MORNING_UTC);

        $ticket = app(QueueService::class)->issueTicket([
            'resident_profile_id' => $this->resident()->id,
            'rhu_id' => 1,
            'service_type' => 'opd_consultation',
        ]);

        $counter = QueueCounter::query()->where('rhu_id', 1)->where('service_type', 'opd_consultation')->first();
        $this->assertSame('2026-10-21', Carbon::parse($counter->queue_date)->toDateString(), "the ticket went on yesterday's counter");

        // 9:00 AM: the clinic is open and the patient is still waiting.
        Carbon::setTestNow('2026-10-21 01:00:00');

        $this->assertTrue(
            QueueTicket::query()->forToday()->whereKey($ticket->id)->exists(),
            "a 7:30 AM ticket dropped out of today's queue at 8:00"
        );
    }

    public function test_the_overnight_sweep_closes_yesterday_and_leaves_this_morning_alone(): void
    {
        $yesterday = $this->ticket('2026-10-20 03:00:00'); // 11:00 AM on the 20th, Manila
        $thisMorning = $this->ticket(self::EARLY_MORNING_UTC); // 7:30 AM on the 21st, Manila

        // 8:10 AM in Manila -- when the sweep used to run on the UTC clock.
        Carbon::setTestNow('2026-10-21 00:10:00');
        $this->artisan('queue:close-stale')->assertSuccessful();

        $this->assertSame('no_show', $yesterday->fresh()->status);
        $this->assertSame('waiting', $thisMorning->fresh()->status, "a patient waiting since 7:30 AM was closed as a no-show");
    }

    public function test_the_day_boundary_jobs_run_on_the_philippine_clock(): void
    {
        $kernel = file_get_contents(app_path('Console/Kernel.php'));

        foreach (["command('queue:close-stale')", "command('outbreak:detect')", "->name('Expire stale prescriptions')"] as $job) {
            $block = substr($kernel, strpos($kernel, $job), 260);
            $this->assertStringContainsString('->timezone(LocalTime::zone())', $block, "{$job} runs on the UTC clock.");
        }
    }

    public function test_medicine_and_prescriptions_are_good_through_their_last_day(): void
    {
        $item = new InventoryItem(['expiration_date' => '2026-10-21']);
        $rx = new Prescription(['valid_until' => '2026-10-21']);

        // 11:00 AM on the 21st in Manila (03:00 UTC): still good.
        Carbon::setTestNow('2026-10-21 03:00:00');
        $this->assertFalse($item->isExpired(), 'expired at 8:00 AM on its own expiry date');
        $this->assertFalse($rx->isExpired(), 'expired at 8:00 AM on its last valid day');

        // 12:30 AM on the 22nd in Manila: expired.
        Carbon::setTestNow('2026-10-21 16:30:00');
        $this->assertTrue($item->isExpired());
        $this->assertTrue($rx->isExpired());
    }

    public function test_nothing_in_app_uses_the_utc_date_for_today(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'LocalTime.php')) {
                continue;
            }

            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/(?<![:>\w])today\(\)|Carbon::today\(\)|now\(\)->toDateString\(\)|[\'"](after|before)(_or_equal)?:today[\'"]/', $line)) {
                    $offenders[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use LocalTime::todayDate() or LocalTime::whereOnDay() for a day people mean.');
    }

    private function appointment(string $date, string $status): Appointment
    {
        $row = [
            'user_id' => $this->resident()->user_id,
            'appointment_date' => $date,
            'status' => $status,
            'consultation_type' => 'onsite',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('appointments', 'rhu_id')) {
            $row['rhu_id'] = 1;
        }

        return Appointment::findOrFail(DB::table('appointments')->insertGetId($row));
    }

    private function ticket(string $issuedAtUtc): QueueTicket
    {
        static $n = 0;

        return QueueTicket::create([
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

    private function staff(): User
    {
        return User::create([
            'role_id' => UserRole::where('name', 'super_admin')->value('role_id'),
            'first_name' => 'Early',
            'last_name' => 'Admin',
            'mobile_number' => '09181007777',
            'email' => 'early.admin@example.test',
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }

    private function resident(): ResidentProfile
    {
        $role = UserRole::firstOrCreate(['name' => 'resident'], ['permissions' => []]);

        $user = User::create([
            'role_id' => $role->role_id,
            'first_name' => 'Resident',
            'last_name' => 'Early' . ($this->phone + 1),
            'mobile_number' => sprintf('0918200%04d', ++$this->phone),
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
