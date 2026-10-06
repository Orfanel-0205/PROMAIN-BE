<?php
// tests/Feature/Security/ResidentAccessTest.php
//
// What an ordinary signed-in RESIDENT must not reach, found by the October
// 2026 handover audit (each was confirmed against production first):
//
//   1. another resident's telemedicine video room (join / signals)
//   2. another resident's telemedicine request, read by its number
//   3. other residents' requests in the telemedicine LIST
//   4. the patient search, which returns names, numbers and emails
//   5. follow-ups: list, summary, create (which can send a billed SMS), edit
//
// plus who may CANCEL a telemedicine request: nurses, midwives, the MHO and
// the super admin, at the request's RHU (the RHU's rule; residents ask the
// RHU); who may send "Notify Patient" (any staff at the RHU); and the AI
// event summary (staff). Every test also checks that the people who need
// access keep it.

namespace Tests\Feature\Security;

use App\Models\FollowUpReminder;
use App\Models\ResidentProfile;
use App\Models\TelemedicineRequest;
use App\Models\TelemedicineSession;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResidentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;        // the resident the records are for
    private User $otherResident;  // an unrelated resident: always refused
    private User $nurse;          // RHU 1
    private User $midwife;        // RHU 1
    private User $nurseRhu2;      // RHU 2: refused for RHU 1 records
    private User $staffAdmin;     // RHU 1 staff who may not cancel
    private User $mho;            // every RHU
    private TelemedicineRequest $request;
    private TelemedicineSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $this->patient = $this->resident('Maria', 'Reyes', '09170000001');
        $this->otherResident = $this->resident('Juan', 'Cruz', '09170000002');

        $this->nurse = $this->staff('nurse', 1, '09170000011');
        $this->midwife = $this->staff('midwife', 1, '09170000012');
        $this->nurseRhu2 = $this->staff('nurse', 2, '09170000013');
        $this->staffAdmin = $this->staff('staff_admin', 1, '09170000014');
        $this->mho = $this->staff('mho', null, '09170000015');

        $this->request = TelemedicineRequest::create([
            'resident_profile_id' => $this->patient->residentProfile->id,
            'requested_by'        => $this->patient->user_id,
            'rhu_id'              => 1,
            'chief_complaint'     => 'Chest pain since last night',
            'urgency_level'       => 'urgent',
            'symptoms'            => ['Chest pain'],
            'status'              => 'pending',
        ]);

        $this->session = TelemedicineSession::create([
            'request_id'         => $this->request->id,
            'assigned_doctor_id' => $this->mho->user_id,
            'status'             => 'scheduled',
            'session_mode'       => 'video_call',
            'scheduled_date'     => now()->toDateString(),
            'scheduled_time'     => '10:00',
        ]);
    }

    // ---------------------------------------------------------------- 1. video

    public function test_a_resident_cannot_get_into_someone_elses_video_call(): void
    {
        $id = $this->session->id;

        $this->actingAs($this->otherResident)->getJson("/api/v1/telemedicine/sessions/{$id}/join")->assertForbidden();
        $this->actingAs($this->otherResident)->getJson("/api/v1/telemedicine/sessions/{$id}/signals")->assertForbidden();
        $this->actingAs($this->otherResident)->postJson("/api/v1/telemedicine/sessions/{$id}/signal", [
            'receiver_id' => $this->mho->user_id,
            'type'        => 'offer',
            'payload'     => ['sdp' => 'x'],
        ])->assertForbidden();

        // The patient and the RHU's staff still can.
        $this->actingAs($this->patient)->getJson("/api/v1/telemedicine/sessions/{$id}/signals")->assertOk();
        $this->actingAs($this->nurse)->getJson("/api/v1/telemedicine/sessions/{$id}/signals")->assertOk();
    }

    // ------------------------------------------------------ 2. one request

    public function test_a_telemedicine_request_is_readable_only_by_its_patient_and_its_rhus_staff(): void
    {
        $url = "/api/v1/telemedicine/requests/{$this->request->id}";

        $this->actingAs($this->otherResident)->getJson($url)->assertForbidden();
        $this->actingAs($this->nurseRhu2)->getJson($url)->assertForbidden();

        $this->actingAs($this->patient)->getJson($url)->assertOk();
        $this->actingAs($this->nurse)->getJson($url)->assertOk();
        $this->actingAs($this->staffAdmin)->getJson($url)->assertOk();
        $this->actingAs($this->mho)->getJson($url)->assertOk();
    }

    // ------------------------------------------------------ 3. the list

    public function test_a_resident_sees_only_their_own_requests_in_the_list(): void
    {
        $this->actingAs($this->otherResident)
            ->getJson('/api/v1/telemedicine/requests?status=all')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->patient)
            ->getJson('/api/v1/telemedicine/requests?status=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Staff keep their RHU's board.
        $this->actingAs($this->nurse)
            ->getJson('/api/v1/telemedicine/requests?status=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------ cancelling

    public function test_only_nurses_midwives_the_mho_and_super_admin_can_cancel_a_request(): void
    {
        $url = "/api/v1/telemedicine/requests/{$this->request->id}";
        $body = ['cancellation_reason' => 'Patient came in person.'];

        // Not even the patient: residents ask the RHU.
        $this->actingAs($this->patient)->deleteJson($url, $body)->assertForbidden();
        $this->actingAs($this->otherResident)->deleteJson($url, $body)->assertForbidden();
        $this->actingAs($this->staffAdmin)->deleteJson($url, $body)->assertForbidden();
        $this->actingAs($this->nurseRhu2)->deleteJson($url, $body)->assertForbidden();
        $this->assertSame('pending', $this->request->fresh()->status);

        foreach ([$this->nurse, $this->midwife, $this->mho] as $allowed) {
            $this->request->update(['status' => 'pending']);

            $this->actingAs($allowed)->deleteJson($url, $body)->assertOk();
            $this->assertSame('cancelled', $this->request->fresh()->status);
        }
    }

    // ------------------------------------------------------ 4. patient search

    public function test_patient_search_is_for_staff_and_returns_only_residents(): void
    {
        $this->actingAs($this->otherResident)
            ->getJson('/api/v1/patients/search?search=0917')
            ->assertForbidden();

        $found = $this->actingAs($this->nurse)
            ->getJson('/api/v1/patients/search?search=0917&limit=20')
            ->assertOk()
            ->json('data');

        // Every account here shares 0917; only the two residents come back.
        $this->assertEqualsCanonicalizing(
            [$this->patient->user_id, $this->otherResident->user_id],
            array_column($found, 'user_id')
        );
    }

    // ------------------------------------------------------ 5. follow-ups

    public function test_follow_ups_are_staff_only(): void
    {
        $reminder = FollowUpReminder::create([
            'user_id'             => $this->patient->user_id,
            'resident_profile_id' => $this->patient->residentProfile->id,
            'rhu_id'              => 1,
            'created_by'          => $this->nurse->user_id,
            'patient_name'        => 'Maria Reyes',
            'mobile_number'       => '09170000001',
            'follow_up_at'        => now()->addDays(3),
            'follow_up_type'      => 'single',
            'follow_up_date'      => now()->addDays(3)->toDateString(),
            'reason'              => 'BP recheck',
            'status'              => 'scheduled',
            'sms_enabled'         => false,
        ]);

        $resident = $this->actingAs($this->otherResident);
        $resident->getJson('/api/v1/follow-up-reminders')->assertForbidden();
        $resident->getJson('/api/v1/follow-ups')->assertForbidden();
        $resident->getJson('/api/v1/follow-up-reminders/summary')->assertForbidden();
        $resident->postJson('/api/v1/follow-up-reminders', ['needs_follow_up' => true, 'force_sms' => true])->assertForbidden();
        $resident->patchJson("/api/v1/follow-up-reminders/{$reminder->id}", ['reason' => 'changed'])->assertForbidden();
        $this->assertSame('BP recheck', $reminder->fresh()->reason);

        // Another RHU's staff cannot edit it either.
        $this->actingAs($this->nurseRhu2)
            ->patchJson("/api/v1/follow-up-reminders/{$reminder->id}", ['reason' => 'changed'])
            ->assertForbidden();

        // The RHU's staff keep the board, at most 100 a page.
        $this->actingAs($this->nurse)
            ->getJson('/api/v1/follow-up-reminders?per_page=5000')
            ->assertOk()
            ->assertJsonPath('per_page', 100)
            ->assertJsonPath('total', 1);
    }

    // ------------------------------------------------------ notify patient

    public function test_only_the_rhus_staff_can_tell_a_patient_the_doctor_is_calling(): void
    {
        Http::fake();
        $url = "/api/v1/telemedicine/sessions/{$this->session->id}/notify-patient";
        $before = DB::table('notifications')->count();

        // Not residents (not even the patient), not another RHU's staff.
        $this->actingAs($this->otherResident)->postJson($url)->assertForbidden();
        $this->actingAs($this->patient)->postJson($url)->assertForbidden();
        $this->actingAs($this->nurseRhu2)->postJson($url)->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame($before, DB::table('notifications')->count());

        // Any staff member of the RHU can send it, so whoever is free does.
        foreach ([$this->nurse, $this->midwife, $this->staffAdmin, $this->mho] as $staff) {
            $this->actingAs($staff)->postJson($url)->assertOk();
        }
    }

    // ------------------------------------------------------ AI event summary

    public function test_the_ai_event_summary_is_staff_only(): void
    {
        $body = ['events' => '1. Free vaccination drive on Friday 2. Dental mission on Monday'];

        $this->actingAs($this->otherResident)->postJson('/api/v1/ai/summarize-events', $body)->assertForbidden();
        $this->actingAs($this->nurse)->postJson('/api/v1/ai/summarize-events', $body)->assertOk();
    }

    // ---------------------------------------------------------------- helpers

    private function role(string $name): UserRole
    {
        return UserRole::where('name', $name)->first()
            ?? UserRole::create(['name' => $name, 'description' => ucfirst($name)]);
    }

    private function resident(string $first, string $last, string $mobile): User
    {
        $user = User::create([
            'role_id'        => $this->role('resident')->role_id,
            'first_name'     => $first,
            'last_name'      => $last,
            'mobile_number'  => $mobile,
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ]);

        ResidentProfile::create([
            'user_id'     => $user->user_id,
            'barangay_id' => \App\Models\Barangay::first()->barangay_id,
            'birth_date'  => '1990-01-01',
        ]);

        return $user->load('residentProfile');
    }

    private function staff(string $role, ?int $rhuId, string $mobile): User
    {
        return User::create([
            'role_id'         => $this->role($role)->role_id,
            'first_name'      => ucfirst($role),
            'last_name'       => 'Staff',
            'mobile_number'   => $mobile,
            'password'        => bcrypt('password'),
            'account_status'  => 'active',
            'assigned_rhu_id' => $rhuId,
        ]);
    }
}
