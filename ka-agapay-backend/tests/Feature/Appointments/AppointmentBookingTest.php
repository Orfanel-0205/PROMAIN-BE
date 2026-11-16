<?php
// tests/Feature/Appointments/AppointmentBookingTest.php
//
// Booking a check-up the way the app does it (16 Nov 2026): the request body
// is the one services/api/appointments.ts builds (reason, preferred_date,
// preferred_time "HH:MM", rhu_id, consultation_type onsite | online). Covers
// the rules a resident meets, the staff approval that notifies them, and that
// the "already passed today" check uses Philippine time on a UTC server.

namespace Tests\Feature\Appointments;

use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $maria;
    private User $juan;
    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);
        Http::fake(); // push and SMS never leave the test

        // Monday 16 Nov 2026, 02:00 UTC = 10:00 in Malasiqui.
        Carbon::setTestNow('2026-11-16 02:00:00');

        $this->maria = $this->resident('Maria', 'Reyes', '09170000001');
        $this->juan = $this->resident('Juan', 'Cruz', '09170000002');
        $this->nurse = User::create([
            'role_id' => UserRole::where('name', 'nurse')->value('role_id'),
            'first_name' => 'Nurse', 'last_name' => 'Staff', 'mobile_number' => '09170000011',
            'password' => bcrypt('password'), 'account_status' => 'active', 'assigned_rhu_id' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_resident_books_an_onsite_check_up_and_sees_it(): void
    {
        $id = $this->book($this->maria, '2026-11-17', '09:00')->assertCreated()->json('appointment.id')
            ?? $this->latestId($this->maria);

        $this->assertDatabaseHas('appointments', [
            'id' => $id, 'user_id' => $this->maria->user_id, 'rhu_id' => 1,
            'status' => 'pending', 'consultation_type' => 'onsite', 'appointment_time' => '09:00',
        ]);

        $mine = $this->actingAs($this->maria)->getJson('/api/v1/appointments/my')->assertOk()->json();
        $this->assertStringContainsString('Cough for 3 days', json_encode($mine));

        $this->actingAs($this->juan)->getJson("/api/v1/appointments/detail/{$id}")->assertNotFound();
    }

    public function test_the_same_day_and_type_twice_is_refused_but_online_is_allowed(): void
    {
        $this->book($this->maria, '2026-11-17', '09:00')->assertCreated();
        $this->book($this->maria, '2026-11-17', '10:00')->assertStatus(409);

        $this->book($this->maria, '2026-11-17', '10:00', 'online')->assertCreated();
        $this->assertSame(1, DB::table('telemedicine_requests')->count(), 'an online booking opens a telemedicine request');
    }

    public function test_dates_and_hours_the_rhu_cannot_take_are_refused(): void
    {
        $this->book($this->maria, '2026-11-15', '09:00')->assertStatus(422);  // yesterday
        $this->book($this->maria, '2026-11-17', '07:00')->assertStatus(422);  // before 8 AM
        $this->book($this->maria, '2026-11-17', '17:30')->assertStatus(422);  // after 5 PM
        $this->assertSame(0, DB::table('appointments')->count());
    }

    public function test_a_time_that_already_passed_today_is_refused_in_philippine_time(): void
    {
        // It is 10:00 in Malasiqui. 09:00 today has passed; 11:00 has not.
        $this->book($this->maria, '2026-11-16', '09:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Appointment schedule cannot be in the past.');

        $this->book($this->maria, '2026-11-16', '11:00')->assertCreated();
    }

    public function test_staff_approval_notifies_the_resident(): void
    {
        $id = $this->book($this->maria, '2026-11-17', '09:00')->assertCreated()->json('appointment.id')
            ?? $this->latestId($this->maria);

        $this->actingAs($this->nurse)
            ->patchJson("/api/v1/admin/appointments/{$id}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame('confirmed', DB::table('appointments')->where('id', $id)->value('status'));

        $inbox = $this->actingAs($this->maria)->getJson('/api/v1/notifications')->assertOk()->json();
        $this->assertStringContainsString('Appointment approved', json_encode($inbox));
    }

    public function test_a_full_time_slot_is_refused(): void
    {
        foreach (['09170000031', '09170000032', '09170000033'] as $mobile) {
            $other = $this->resident('Other', 'Resident', $mobile);
            $id = $this->book($other, '2026-11-17', '09:00')->assertCreated()->json('appointment.id') ?? $this->latestId($other);
            $this->actingAs($this->nurse)->patchJson("/api/v1/admin/appointments/{$id}/status", ['status' => 'confirmed'])->assertOk();
        }

        $this->book($this->maria, '2026-11-17', '09:00')->assertStatus(422);
        $this->book($this->maria, '2026-11-17', '09:30')->assertCreated();
    }

    public function test_the_resident_can_cancel_their_own_booking(): void
    {
        $id = $this->book($this->maria, '2026-11-17', '09:00')->assertCreated()->json('appointment.id')
            ?? $this->latestId($this->maria);

        $other = $this->actingAs($this->juan)->patchJson("/api/v1/appointments/{$id}/status", ['status' => 'cancelled'])->status();
        $this->assertContains($other, [403, 404], 'another resident cannot cancel it');
        $this->assertSame('pending', DB::table('appointments')->where('id', $id)->value('status'));

        $this->actingAs($this->maria)
            ->patchJson("/api/v1/appointments/{$id}/status", ['status' => 'cancelled', 'notes' => 'Cancelled by patient.'])
            ->assertOk();
        $this->assertSame('cancelled', DB::table('appointments')->where('id', $id)->value('status'));

        // Cancelled frees the day for a new booking.
        $this->book($this->maria, '2026-11-17', '10:00')->assertCreated();
    }

    // ------------------------------------------------------------ helpers

    /** The body services/api/appointments.ts appointmentRequestBody() sends. */
    private function book(User $user, string $date, string $time, string $type = 'onsite')
    {
        return $this->actingAs($user)->postJson('/api/v1/appointments', [
            'consultation_type' => $type,
            'reason' => 'Cough for 3 days',
            'symptoms' => null,
            'preferred_date' => $date,
            'appointment_date' => $date,
            'preferred_time' => $time,
            'appointment_time' => $time,
            'purpose' => '[' . strtoupper($type) . " CONSULTATION]\nReason: Cough for 3 days",
            'urgency_level' => 'routine',
            'rhu_id' => 1,
        ]);
    }

    private function latestId(User $user): int
    {
        return (int) DB::table('appointments')->where('user_id', $user->user_id)->max('id');
    }

    private function resident(string $first, string $last, string $mobile): User
    {
        $user = User::create([
            'role_id' => UserRole::where('name', 'resident')->value('role_id'),
            'first_name' => $first, 'last_name' => $last, 'mobile_number' => $mobile,
            'password' => bcrypt('password'), 'account_status' => 'active',
        ]);

        ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => \App\Models\Barangay::first()->barangay_id,
            'birth_date' => '1990-01-01',
        ]);

        return $user;
    }
}
