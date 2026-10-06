<?php
// tests/Feature/Events/EventOperationsTest.php
//
// Events as the RHU runs them (Oct 2026):
//   - an event for Buto is pinned at Buto on the queue heatmap;
//   - an RHU's staff are alerted when its queue turns heavy or over capacity,
//     or an event is nearly full -- once per rise;
//   - an event that has ended leaves the residents' list (and the map), and
//     its report is announced to staff once;
//   - the report counts who came (marked by staff) and what was handed out
//     (stock-outs tagged with the event).

namespace Tests\Feature\Events;

use App\Models\Barangay;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\InventoryItem;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\NotificationTypes;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $mho;
    private User $nurse;      // RHU 1
    private User $nurseRhu2;  // RHU 2
    private User $resident;
    private int $phone = 0;
    private int $tickets = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);
        $this->seed(\Database\Seeders\BarangayCoordinatesSeeder::class);

        $this->superAdmin = $this->user('super_admin');
        $this->mho = $this->user('mho');
        $this->nurse = $this->user('nurse', 1);
        $this->nurseRhu2 = $this->user('nurse', 2);
        $this->resident = $this->user('resident');

        ResidentProfile::create([
            'user_id' => $this->resident->user_id,
            'barangay_id' => $this->barangay('Buto')->barangay_id,
            'birth_date' => '1990-01-01',
        ]);
    }

    // -------------------------------------------------------------- the map

    public function test_an_event_is_pinned_at_its_target_barangays(): void
    {
        $buto = $this->barangay('Buto');
        $this->event('Free check-up in Buto', ['barangay_target' => 'Buto']);
        $this->event('For the whole town', ['barangay_target' => 'all']);
        $this->event('RHU 2 only', ['barangay_target' => 'all', 'visibility' => 'rhu2']);

        $events = collect($this->actingAs($this->superAdmin)->getJson('/api/v1/admin/events?per_page=50')
            ->assertOk()->json('data'))->keyBy('title');

        $pin = $events['Free check-up in Buto']['pins'][0];
        $this->assertSame('Buto', $pin['barangay']);
        $this->assertEqualsWithDelta((float) $buto->latitude, $pin['latitude'], 0.00001);
        $this->assertEqualsWithDelta((float) $buto->longitude, $pin['longitude'], 0.00001);

        $this->assertSame([], $events['For the whole town']['pins']);
        $this->assertSame(2, $events['RHU 2 only']['host_rhu_id']);
    }

    public function test_an_event_posted_by_rhu_staff_is_hosted_by_their_rhu(): void
    {
        $this->event('Nurse event', ['barangay_target' => 'Buto', 'created_by' => $this->nurseRhu2->user_id]);

        $event = collect($this->actingAs($this->superAdmin)->getJson('/api/v1/admin/events')->json('data'))
            ->firstWhere('title', 'Nurse event');

        $this->assertSame(2, $event['host_rhu_id']);
    }

    // -------------------------------------------------------- ended events

    public function test_an_ended_event_leaves_the_residents_list_and_cannot_be_joined(): void
    {
        $ended = $this->event('Yesterday', ['starts_at' => $this->manila('-1 day 08:00')]);
        $today = $this->event('Today', ['starts_at' => $this->manila('today 08:00')]);
        $this->event('Next week', ['starts_at' => $this->manila('+7 days 08:00')]);
        $this->event('Old announcement', ['event_type' => 'announcement', 'starts_at' => $this->manila('-10 days 08:00')]);

        $titles = collect($this->actingAs($this->resident)->getJson('/api/v1/programs')->assertOk()->json('data'))
            ->pluck('title')->all();

        $this->assertContains('Today', $titles, 'An event without an end time stays up until midnight.');
        $this->assertContains('Next week', $titles);
        $this->assertContains('Old announcement', $titles, 'Announcements are not events and stay.');
        $this->assertNotContains('Yesterday', $titles);

        $this->actingAs($this->resident)->postJson("/api/v1/programs/{$ended->id}/register")
            ->assertStatus(422)->assertJsonPath('message', 'This event has already ended.');
        $this->actingAs($this->resident)->postJson("/api/v1/programs/{$today->id}/register")
            ->assertSuccessful();
    }

    // ------------------------------------------------------------ attendance

    public function test_staff_mark_attendance_from_the_day_of_the_event_and_it_is_audited(): void
    {
        $today = $this->event('Today', ['starts_at' => $this->manila('today 08:00')]);
        $later = $this->event('Later', ['starts_at' => $this->manila('+3 days 08:00')]);
        $here = $this->registration($today, $this->resident);
        $notYet = $this->registration($later, $this->resident);

        $this->actingAs($this->resident)
            ->patchJson("/api/v1/admin/events/{$today->id}/registrants/{$here->id}/attendance", ['status' => 'attended'])
            ->assertForbidden();

        $this->actingAs($this->nurse)
            ->patchJson("/api/v1/admin/events/{$later->id}/registrants/{$notYet->id}/attendance", ['status' => 'attended'])
            ->assertStatus(422);

        $this->actingAs($this->nurse)
            ->patchJson("/api/v1/admin/events/{$today->id}/registrants/{$here->id}/attendance", ['status' => 'attended'])
            ->assertOk();

        $here->refresh();
        $this->assertSame('attended', $here->status);
        $this->assertSame($this->nurse->user_id, (int) $here->attendance_marked_by);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'event.attendance_marked')->exists());
    }

    // ---------------------------------------------------------------- report

    public function test_the_report_counts_who_came_and_what_was_handed_out(): void
    {
        $event = $this->event('Deworming day', ['starts_at' => $this->manila('today 08:00'), 'barangay_target' => 'Buto']);

        $this->registration($event, $this->resident, 'attended');
        $this->registration($event, $this->user('resident'), 'no_show');
        $this->registration($event, $this->user('resident'), 'registered');
        $this->registration($event, $this->user('resident'), 'cancelled');

        $item = InventoryItem::create([
            'rhu_id' => 1, 'item_code' => 'MED-ALB-0001', 'name' => 'Albendazole', 'category' => 'medicine',
            'unit_of_measure' => 'tablet', 'current_stock' => 100, 'minimum_stock_level' => 0, 'is_active' => true,
        ]);

        foreach ([10, 5] as $quantity) {
            $this->actingAs($this->nurse)
                ->postJson("/api/v1/inventory/{$item->id}/stock-out", [
                    'quantity' => $quantity,
                    'reason' => 'Handed out at the deworming day',
                    'event_id' => $event->id,
                ])
                ->assertOk();
        }

        $report = $this->actingAs($this->nurse)->getJson("/api/v1/admin/events/{$event->id}/report")
            ->assertOk()->json('data');

        $this->assertSame(['registered' => 3, 'attended' => 1, 'no_show' => 1, 'not_marked' => 1, 'cancelled' => 1, 'items_dispensed' => 15], $report['summary']);
        $this->assertSame([['item' => 'Albendazole', 'unit' => 'tablet', 'quantity' => 15]], $report['dispensed_totals']);
        $this->assertCount(2, $report['dispensed']);
        $this->assertSame('Nurse Staff1', $report['dispensed'][0]['recorded_by']);
        $this->assertCount(3, $report['attendees'], 'Cancelled registrations are not listed.');
        $this->assertSame('Buto', collect($report['attendees'])->firstWhere('status', 'attended')['barangay']);

        $this->actingAs($this->resident)->getJson("/api/v1/admin/events/{$event->id}/report")->assertForbidden();
    }

    // --------------------------------------------------------- queue alerts

    public function test_an_overloaded_queue_alerts_its_rhus_staff_once_per_rise(): void
    {
        $patient = $this->resident->residentProfile;

        $this->tickets(26, $patient, 1);
        $this->artisan('queue:pressure-alerts')->assertSuccessful();

        $this->assertSame(1, $this->alertsFor($this->nurse, NotificationTypes::QUEUE_OVERLOAD));
        $this->assertSame(1, $this->alertsFor($this->mho, NotificationTypes::QUEUE_OVERLOAD), 'The MHO oversees every RHU.');
        $this->assertSame(0, $this->alertsFor($this->nurseRhu2, NotificationTypes::QUEUE_OVERLOAD), 'RHU 2 is not busy.');

        // Still heavy a minute later: no repeat.
        $this->artisan('queue:pressure-alerts')->assertSuccessful();
        $this->assertSame(1, $this->alertsFor($this->nurse, NotificationTypes::QUEUE_OVERLOAD));

        // Over capacity: a new alert.
        $this->tickets(25, $patient, 1);
        $this->artisan('queue:pressure-alerts')->assertSuccessful();
        $this->assertSame(2, $this->alertsFor($this->nurse, NotificationTypes::QUEUE_OVERLOAD));
    }

    public function test_a_nearly_full_event_alerts_staff(): void
    {
        $event = $this->event('Vaccination', [
            'starts_at' => $this->manila('+2 days 08:00'),
            'max_slots' => 10,
            'slots_available' => 2,
            'visibility' => 'rhu1',
        ]);

        foreach (range(1, 8) as $i) {
            $this->registration($event, $this->user('resident'));
        }

        $this->artisan('queue:pressure-alerts')->assertSuccessful();

        $this->assertSame(1, $this->alertsFor($this->nurse, NotificationTypes::EVENT_CROWDING));
        $this->assertSame(0, $this->alertsFor($this->nurseRhu2, NotificationTypes::EVENT_CROWDING));
    }

    // ----------------------------------------------------- end of an event

    public function test_an_ended_event_announces_its_report_once_and_old_ones_quietly(): void
    {
        $yesterday = $this->event('Yesterday', ['starts_at' => $this->manila('-1 day 08:00'), 'created_by' => $this->nurse->user_id]);
        $longAgo = $this->event('Long ago', ['starts_at' => $this->manila('-6 days 08:00'), 'created_by' => $this->nurse->user_id]);
        $tomorrow = $this->event('Tomorrow', ['starts_at' => $this->manila('+1 day 08:00')]);

        $this->artisan('events:close-ended')->assertSuccessful();
        $this->artisan('events:close-ended')->assertSuccessful();

        $this->assertNotNull($yesterday->fresh()->report_generated_at);
        $this->assertNotNull($longAgo->fresh()->report_generated_at);
        $this->assertNull($tomorrow->fresh()->report_generated_at);

        $this->assertSame(1, $this->alertsFor($this->nurse, NotificationTypes::EVENT_REPORT_READY), 'Once, and only for the recent one.');
        $this->assertSame(0, $this->alertsFor($this->nurseRhu2, NotificationTypes::EVENT_REPORT_READY));
    }

    // ---------------------------------------------------------------- helpers

    private function user(string $role, ?int $rhuId = null): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);
        $n = ++$this->phone;

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Staff' . ($role === 'nurse' ? $n - 2 : $n),
            'mobile_number' => sprintf('0917555%04d', $n),
            'password' => bcrypt('password'),
            'account_status' => 'active',
            'assigned_rhu_id' => $rhuId,
        ])->load('role');
    }

    private function barangay(string $name): Barangay
    {
        return Barangay::where('name', $name)->firstOrFail();
    }

    private function event(string $title, array $attributes = []): Event
    {
        $startsAt = $attributes['starts_at'] ?? $this->manila('+2 days 08:00');

        return Event::create(array_merge([
            'title' => $title,
            'description' => $title,
            'event_type' => 'event',
            'category' => 'health',
            'event_date' => $startsAt,
            'barangay_target' => 'all',
            'visibility' => 'public',
            'is_published' => true,
            'published_at' => now()->subDay(),
            'created_by' => $this->superAdmin->user_id,
        ], $attributes, ['starts_at' => $startsAt, 'event_date' => $attributes['event_date'] ?? $startsAt]));
    }

    private function registration(Event $event, User $user, string $status = 'registered'): EventRegistration
    {
        return EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $user->user_id,
            'status' => $status,
            'registered_at' => now(),
        ]);
    }

    private function tickets(int $count, ResidentProfile $patient, int $rhuId): void
    {
        foreach (range(1, $count) as $i) {
            QueueTicket::create([
                'ticket_number' => 'Q' . str_pad((string) ++$this->tickets, 4, '0', STR_PAD_LEFT),
                'resident_profile_id' => $patient->id,
                'rhu_id' => $rhuId,
                'service_type' => 'opd_consultation',
                'status' => 'waiting',
                'priority_score' => 0,
                'issued_at' => now(),
            ]);
        }
    }

    private function alertsFor(User $user, string $type): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->user_id)
            ->where('type', $type)
            ->count();
    }

    /** A time in the Philippines, e.g. "-1 day 08:00", as UTC. */
    private function manila(string $when): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse($when, LocalTime::zone())->utc();
    }
}
