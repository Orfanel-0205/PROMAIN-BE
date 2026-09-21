<?php
// tests/Feature/Queue/AppointmentQueueDateTest.php
//
// A ticket belongs to the day of the visit, not the day of the paperwork.
//
// Approving an appointment creates the patient's queue ticket. The ticket used
// to be stamped with the moment of approval, so approving Monday a visit
// booked for Friday put a ticket into MONDAY's queue. It waited there, was
// counted as a patient standing in the building, and was never called because
// nobody was there to call. That is where a dashboard reporting ten people
// waiting beside an empty desk came from.
//
// It gets worse once the end-of-day sweep exists: the misdated ticket is
// closed overnight as a no-show, and on Friday the patient arrives to find
// their only ticket already dead, with nothing on screen to explain it.
//
// These tests hold both halves: the ticket lands on the right day, and a
// patient whose ticket was killed can still be queued when they turn up.

namespace Tests\Feature\Queue;

use App\Models\Appointment;
use App\Models\QueueTicket;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Queue\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppointmentQueueDateTest extends TestCase
{
    use RefreshDatabase;

    private int $rhuId;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        // queue_tickets.rhu_id is a foreign key to barangays in this schema.
        $this->rhuId = (int) DB::table('barangays')->min('barangay_id');
    }

    public function test_a_visit_booked_for_next_week_does_not_join_todays_queue(): void
    {
        $appointment = $this->makeAppointment(today()->addDays(5));

        $ticket = app(QueueService::class)->syncAppointmentToQueue($appointment);

        $this->assertNotNull($ticket);
        $this->assertSame(
            today()->addDays(5)->toDateString(),
            $ticket->issued_at->toDateString(),
            'the ticket was filed under the day it was approved, not the day of the visit'
        );

        // The queue board for today reads issued_at, so this is the assertion
        // that maps to what staff actually see.
        $this->assertSame(0, QueueTicket::query()->forToday()->count());
    }

    public function test_a_walk_in_is_still_for_today(): void
    {
        $resident = $this->makeResident();

        $ticket = app(QueueService::class)->issueTicket([
            'resident_profile_id' => $resident->id,
            'rhu_id' => $this->rhuId,
            'service_type' => 'opd_consultation',
        ]);

        $this->assertSame(today()->toDateString(), $ticket->issued_at->toDateString());
        $this->assertSame(1, QueueTicket::query()->forToday()->count());
    }

    public function test_a_patient_whose_ticket_was_closed_can_still_be_queued_on_the_day(): void
    {
        $appointment = $this->makeAppointment(today()->addDays(3));

        $first = app(QueueService::class)->syncAppointmentToQueue($appointment);

        // However it died -- cancelled, or closed by the overnight sweep.
        $first->forceFill(['status' => 'no_show'])->save();

        $second = app(QueueService::class)->syncAppointmentToQueue($appointment->fresh());

        $this->assertNotNull($second);
        $this->assertNotSame(
            $first->id,
            $second->id,
            'a dead ticket was handed back, leaving the patient with no way into the queue'
        );
        $this->assertSame('waiting', $second->status);
    }

    public function test_a_visit_that_has_already_passed_is_not_given_a_new_ticket(): void
    {
        // Reissuing for a date in the past would resurrect people who never
        // came, and quietly inflate tomorrow's queue with last week's patients.
        $appointment = $this->makeAppointment(today()->subDays(4));

        $first = app(QueueService::class)->syncAppointmentToQueue($appointment);
        $first->forceFill(['status' => 'no_show'])->save();

        $second = app(QueueService::class)->syncAppointmentToQueue($appointment->fresh());

        $this->assertSame($first->id, $second->id);
    }

    public function test_approving_the_same_appointment_twice_does_not_double_book(): void
    {
        $appointment = $this->makeAppointment(today()->addDay());

        $first = app(QueueService::class)->syncAppointmentToQueue($appointment);
        $second = app(QueueService::class)->syncAppointmentToQueue($appointment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, QueueTicket::query()->count());
    }

    private function makeAppointment($date): Appointment
    {
        $resident = $this->makeResident();

        $row = [
            'user_id' => $resident->user_id,
            'appointment_date' => $date->toDateString(),
            'status' => 'approved',
            'consultation_type' => 'onsite',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('appointments', 'rhu_id')) {
            $row['rhu_id'] = $this->rhuId;
        }

        $id = DB::table('appointments')->insertGetId($row);

        return Appointment::findOrFail($id);
    }

    private function makeResident(): ResidentProfile
    {
        $roleRow = UserRole::firstOrCreate(['name' => 'resident'], ['permissions' => []]);

        $user = User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => 'Resident',
            'last_name' => 'Tester' . ($this->phone + 1),
            'mobile_number' => sprintf('0917900%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        return ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => $this->rhuId,
        ]);
    }
}
