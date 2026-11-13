<?php
// tests/Feature/Security/PatientDataAccessTest.php
//
// Who may reach a patient's profile and records, checked end to end before
// the LGU handover (13 Nov 2026). Each test names the rule it holds:
//
//   1. nothing about a patient is readable without signing in
//   2. a resident reaches only their own profile and records, and cannot
//      give themselves a role, a status, an RHU or someone else's id
//   3. the staff-only screens (registry, profile, consultations, export,
//      analytics, users, ID photos) refuse residents, and the registry and
//      profile refuse a BHW
//   4. a staff member at one RHU cannot open, edit or cancel another RHU's
//      consultation or prescription by guessing its number; the MHO and the
//      Super Admin can; the clinician who made the record keeps it
//   5. a token stops working when the account is switched off, suspended,
//      locked, deleted, signed out, or older than seven days
//   6. no answer carries a password hash or a token, and a wrong sign-in
//      does not say whether the number exists

namespace Tests\Feature\Security;

use App\Models\Consultation;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientDataAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;        // resident with records at RHU 1 and RHU 2
    private User $otherResident;  // unrelated resident
    private User $nurse;          // RHU 1
    private User $nurseRhu2;      // RHU 2
    private User $doctor;         // RHU 1, wrote the RHU 1 prescription
    private User $doctorRhu2;     // RHU 2
    private User $bhw;            // RHU 1
    private User $mho;            // every RHU
    private User $superAdmin;     // every RHU

    private Consultation $consultRhu1;
    private Consultation $consultRhu2;
    private int $rxRhu1;
    private int $rxRhu2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $this->patient = $this->resident('Maria', 'Reyes', '09170000001');
        $this->otherResident = $this->resident('Juan', 'Cruz', '09170000002');

        $this->nurse = $this->staff('nurse', 1, '09170000011');
        $this->nurseRhu2 = $this->staff('nurse', 2, '09170000012');
        $this->doctor = $this->staff('doctor', 1, '09170000013');
        $this->doctorRhu2 = $this->staff('doctor', 2, '09170000014');
        $this->bhw = $this->staff('bhw', 1, '09170000015');
        $this->mho = $this->staff('mho', null, '09170000016');
        $this->superAdmin = $this->staff('super_admin', null, '09170000017');

        $this->consultRhu1 = $this->consultation(1, $this->nurse);
        $this->consultRhu2 = $this->consultation(2, $this->nurseRhu2);

        $this->rxRhu1 = $this->prescription(1, $this->doctor, 'RHU1-RX-2026-00001');
        $this->rxRhu2 = $this->prescription(2, $this->doctorRhu2, 'RHU2-RX-2026-00001');
    }

    // ------------------------------------------------------ 1. signed out

    public function test_nothing_about_a_patient_is_readable_without_signing_in(): void
    {
        $urls = [
            '/api/v1/patient/me',
            '/api/v1/profile',
            '/api/v1/patients/registry',
            "/api/v1/patients/{$this->patient->user_id}/profile",
            '/api/v1/admin/consultations',
            "/api/v1/admin/consultations/{$this->consultRhu1->id}",
            "/api/v1/consultations/{$this->consultRhu1->id}",
            "/api/v1/appointments/{$this->patient->user_id}",
            '/api/v1/prescriptions',
            "/api/v1/prescriptions/{$this->rxRhu1}",
            "/api/v1/prescriptions/{$this->rxRhu1}/pdf",
            '/api/v1/admin/consultations/export',
            '/api/v1/analytics/overview',
        ];

        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    // ------------------------------------------------------ 2. a resident

    public function test_a_resident_reads_only_their_own_profile_and_records(): void
    {
        $me = $this->actingAs($this->patient)->getJson('/api/v1/patient/me')->assertOk();
        $this->assertSame($this->patient->user_id, (int) $me->json('data.user_id'));

        $this->actingAs($this->patient)
            ->getJson("/api/v1/consultations/{$this->consultRhu1->id}")
            ->assertOk();

        // The other resident: not found, never forbidden (no hint it exists).
        $this->actingAs($this->otherResident)
            ->getJson("/api/v1/consultations/{$this->consultRhu1->id}")
            ->assertNotFound();
        $this->actingAs($this->otherResident)
            ->getJson("/api/v1/prescriptions/{$this->rxRhu1}")
            ->assertNotFound();
        $this->actingAs($this->otherResident)
            ->getJson("/api/v1/prescriptions/{$this->rxRhu1}/pdf")
            ->assertNotFound();
        $this->actingAs($this->otherResident)
            ->getJson("/api/v1/appointments/{$this->patient->user_id}")
            ->assertForbidden();

        $list = $this->actingAs($this->otherResident)->getJson('/api/v1/consultations')->assertOk()->json();
        $this->assertStringNotContainsString('Maria', json_encode($list));
    }

    public function test_a_resident_cannot_change_their_own_role_status_rhu_or_identity(): void
    {
        $before = $this->patient->fresh();

        $this->actingAs($this->patient)->patchJson('/api/v1/patient/me', [
            'first_name' => 'Maria',
            'role_id' => UserRole::where('name', 'super_admin')->value('role_id'),
            'role' => 'super_admin',
            'account_status' => 'active',
            'assigned_rhu_id' => 1,
            'is_staff' => true,
            'user_id' => $this->otherResident->user_id,
            'password' => 'Hacked-123!',
            'id_verified' => true,
        ])->assertOk();

        $after = $this->patient->fresh();
        $this->assertSame($before->role_id, $after->role_id);
        $this->assertSame($before->assigned_rhu_id, $after->assigned_rhu_id);
        $this->assertSame($before->password, $after->password);
        $this->assertSame($before->user_id, $after->user_id);
        $this->assertFalse((bool) $after->is_staff);
        $this->assertSame((bool) $before->id_verified, (bool) $after->id_verified);

        // The same through the general profile endpoint.
        $this->actingAs($this->patient)->patchJson('/api/v1/profile', [
            'first_name' => 'Maria',
            'role_id' => UserRole::where('name', 'mho')->value('role_id'),
            'account_status' => 'suspended',
            'assigned_rhu_id' => 2,
        ]);
        $after = $this->patient->fresh();
        $this->assertSame($before->role_id, $after->role_id);
        $this->assertSame('active', $after->account_status);
        $this->assertSame($before->assigned_rhu_id, $after->assigned_rhu_id);
    }

    // ------------------------------------------------------ 3. staff screens

    public function test_staff_only_screens_refuse_residents(): void
    {
        $urls = [
            '/api/v1/patients/registry',
            "/api/v1/patients/{$this->patient->user_id}/profile",
            '/api/v1/admin/consultations',
            "/api/v1/admin/consultations/{$this->consultRhu1->id}",
            "/api/v1/admin/consultations/{$this->consultRhu1->id}/signature",
            '/api/v1/admin/consultations/export',
            '/api/v1/admin/consultations/diagnosis-itr',
            '/api/v1/analytics/overview',
            '/api/v1/admin/users',
            '/api/v1/admin/registrations',
            "/api/v1/admin/registrations/{$this->otherResident->user_id}/ocr/file",
            '/api/v1/patients/search?search=Maria',
        ];

        foreach ($urls as $url) {
            $status = $this->actingAs($this->otherResident)->getJson($url)->status();
            // Refused either way: 403 from the role check, 404 where the screen
            // hides that the record exists.
            $this->assertContains($status, [403, 404], "{$url} answered {$status}");
        }

        $this->actingAs($this->otherResident)
            ->putJson("/api/v1/admin/consultations/{$this->consultRhu1->id}/soap", ['subjective' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->otherResident)
            ->patchJson("/api/v1/prescriptions/{$this->rxRhu1}", ['diagnosis' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->otherResident)
            ->deleteJson("/api/v1/prescriptions/{$this->rxRhu1}")
            ->assertForbidden();
    }

    public function test_a_bhw_sees_consultations_but_not_the_registry_or_a_profile(): void
    {
        $this->actingAs($this->bhw)->getJson('/api/v1/admin/consultations')->assertOk();
        $this->actingAs($this->bhw)->getJson('/api/v1/patients/registry')->assertForbidden();
        $this->actingAs($this->bhw)
            ->getJson("/api/v1/patients/{$this->patient->user_id}/profile")
            ->assertForbidden();
    }

    public function test_a_patient_profile_shows_the_viewers_rhu_visits_and_the_mho_sees_all(): void
    {
        $this->consultRhu1->update(['status' => 'completed', 'completed_at' => now(), 'diagnosis' => 'URTI']);
        $this->consultRhu2->update(['status' => 'completed', 'completed_at' => now(), 'diagnosis' => 'Hypertension']);

        $rhu1 = $this->actingAs($this->nurse)
            ->getJson("/api/v1/patients/{$this->patient->user_id}/profile")
            ->assertOk()->json('data');
        $this->assertSame(1, $rhu1['summary']['total_visits']);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'patients',
            'action' => 'patient.profile_viewed',
            'user_id' => $this->nurse->user_id,
        ]);

        $all = $this->actingAs($this->mho)
            ->getJson("/api/v1/patients/{$this->patient->user_id}/profile")
            ->assertOk()->json('data');
        $this->assertSame(2, $all['summary']['total_visits']);
    }

    // ------------------------------------------------------ 4. across RHUs

    public function test_staff_cannot_open_another_rhus_consultation_by_its_number(): void
    {
        $url = "/api/v1/admin/consultations/{$this->consultRhu2->id}";

        $this->actingAs($this->nurse)->getJson($url)->assertNotFound();
        $this->actingAs($this->doctor)->getJson($url)->assertNotFound();
        $this->actingAs($this->nurse)->getJson($url . '/signature')->assertNotFound();

        // Opening it must not have marked the wrong nurse as first attender.
        $this->assertNull($this->consultRhu2->fresh()->first_attended_by);

        $this->actingAs($this->nurseRhu2)->getJson($url)->assertOk();
        $this->actingAs($this->mho)->getJson($url)->assertOk();
        $this->actingAs($this->superAdmin)->getJson($url)->assertOk();

        // The RHU 1 list never carries the RHU 2 record either.
        $ids = collect($this->actingAs($this->nurse)->getJson('/api/v1/admin/consultations')->assertOk()->json('data'))
            ->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertFalse($ids->contains($this->consultRhu2->id));
    }

    public function test_staff_cannot_write_another_rhus_consultation(): void
    {
        $id = $this->consultRhu2->id;

        $this->actingAs($this->nurse)
            ->putJson("/api/v1/admin/consultations/{$id}/soap", ['subjective' => 'tampered'])
            ->assertStatus(403);
        $this->actingAs($this->doctor)
            ->patchJson("/api/v1/admin/consultations/{$id}/complete", ['diagnosis' => 'tampered'])
            ->assertStatus(403);

        $fresh = $this->consultRhu2->fresh();
        $this->assertNotSame('tampered', $fresh->subjective);
        $this->assertNotSame('tampered', $fresh->diagnosis);
        $this->assertNotSame('completed', strtolower((string) $fresh->status));
    }

    public function test_a_prescriber_cannot_edit_or_cancel_another_rhus_prescription(): void
    {
        $this->actingAs($this->doctor)
            ->patchJson("/api/v1/prescriptions/{$this->rxRhu2}", ['diagnosis' => 'tampered'])
            ->assertNotFound();
        $this->actingAs($this->doctor)
            ->deleteJson("/api/v1/prescriptions/{$this->rxRhu2}")
            ->assertNotFound();

        $row = DB::table('prescriptions')->find($this->rxRhu2);
        $this->assertNotSame('tampered', $row->diagnosis);
        $this->assertSame('active', $row->status);

        // The prescriber who wrote it, the MHO and the Super Admin still can.
        $this->actingAs($this->doctorRhu2)
            ->patchJson("/api/v1/prescriptions/{$this->rxRhu2}", ['diagnosis' => 'URTI'])
            ->assertOk();
        $this->actingAs($this->mho)
            ->patchJson("/api/v1/prescriptions/{$this->rxRhu2}", ['diagnosis' => 'URTI, follow up'])
            ->assertOk();
        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/prescriptions/{$this->rxRhu2}")
            ->assertOk();
        $this->assertSame('cancelled', DB::table('prescriptions')->find($this->rxRhu2)->status);
    }

    public function test_a_nurse_cannot_edit_or_cancel_a_prescription_at_all(): void
    {
        $this->actingAs($this->nurse)
            ->patchJson("/api/v1/prescriptions/{$this->rxRhu1}", ['diagnosis' => 'tampered'])
            ->assertForbidden();
        $this->actingAs($this->nurse)
            ->deleteJson("/api/v1/prescriptions/{$this->rxRhu1}")
            ->assertForbidden();
        $this->assertSame('active', DB::table('prescriptions')->find($this->rxRhu1)->status);
    }

    // ------------------------------------------------------ 5. the token

    public function test_a_token_stops_working_when_the_account_is_switched_off(): void
    {
        $token = $this->patient->createToken('phone')->plainTextToken;
        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->getJson('/api/v1/patient/me', $headers)->assertOk();

        // Each request below is a fresh one from the phone: the test runner
        // would otherwise keep the user it resolved for the request before.
        foreach (['inactive', 'suspended', 'rejected', 'pending'] as $status) {
            $this->patient->forceFill(['account_status' => $status])->save();
            $this->app['auth']->forgetGuards();
            $this->assertSame(403, $this->getJson('/api/v1/patient/me', $headers)->status(), "status {$status} still answers");
        }

        $this->patient->forceFill(['account_status' => 'active', 'locked_until' => now()->addMinutes(10)])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/patient/me', $headers)->assertStatus(423);

        $this->patient->forceFill(['locked_until' => null])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/patient/me', $headers)->assertOk();

        $this->patient->delete();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/patient/me', $headers)->assertUnauthorized();
    }

    public function test_signing_out_and_seven_days_both_end_a_token(): void
    {
        $token = $this->patient->createToken('phone')->plainTextToken;
        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->getJson('/api/v1/patient/me', $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/logout', [], $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/patient/me', $headers)->assertUnauthorized();

        $old = $this->patient->createToken('old-phone')->plainTextToken;
        DB::table('personal_access_tokens')->update(['created_at' => now()->subDays(8)]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/patient/me', ['Authorization' => 'Bearer ' . $old])->assertUnauthorized();
    }

    // ------------------------------------------------------ 6. what leaks

    public function test_no_answer_carries_a_password_hash_or_a_token(): void
    {
        $answers = [
            $this->actingAs($this->patient)->getJson('/api/v1/patient/me')->assertOk()->getContent(),
            $this->actingAs($this->patient)->getJson('/api/v1/profile')->assertOk()->getContent(),
            $this->actingAs($this->nurse)->getJson('/api/v1/patients/registry')->assertOk()->getContent(),
            $this->actingAs($this->nurse)->getJson("/api/v1/patients/{$this->patient->user_id}/profile")->assertOk()->getContent(),
            $this->actingAs($this->nurse)->getJson("/api/v1/admin/consultations/{$this->consultRhu1->id}")->assertOk()->getContent(),
            $this->actingAs($this->nurse)->getJson('/api/v1/patients/search?search=Maria')->assertOk()->getContent(),
            $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/users')->assertOk()->getContent(),
        ];

        foreach ($answers as $body) {
            $this->assertStringNotContainsString('$2y$', $body, 'a bcrypt hash was sent');
            $this->assertStringNotContainsString('"password"', $body);
            $this->assertStringNotContainsString('remember_token', $body);
            $this->assertStringNotContainsString('otp_code', $body);
            $this->assertStringNotContainsString('biometric_token_hash', $body);
        }
    }

    public function test_a_wrong_sign_in_does_not_say_whether_the_number_exists(): void
    {
        $known = $this->postJson('/api/v1/login', ['mobile_number' => '09170000001', 'password' => 'wrong-password']);
        $unknown = $this->postJson('/api/v1/login', ['mobile_number' => '09179999999', 'password' => 'wrong-password']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    // ------------------------------------------------------ fixtures

    private function role(string $name): UserRole
    {
        return UserRole::where('name', $name)->firstOrFail();
    }

    private function resident(string $first, string $last, string $mobile): User
    {
        $user = User::create([
            'role_id' => $this->role('resident')->role_id,
            'first_name' => $first,
            'last_name' => $last,
            'mobile_number' => $mobile,
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);

        ResidentProfile::create([
            'user_id' => $user->user_id,
            'barangay_id' => \App\Models\Barangay::first()->barangay_id,
            'birth_date' => '1990-01-01',
        ]);

        return $user->load('residentProfile');
    }

    private function staff(string $role, ?int $rhuId, string $mobile): User
    {
        return User::create([
            'role_id' => $this->role($role)->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Staff',
            'mobile_number' => $mobile,
            'password' => bcrypt('password'),
            'account_status' => 'active',
            'assigned_rhu_id' => $rhuId,
        ]);
    }

    private function consultation(int $rhuId, User $attendedBy): Consultation
    {
        $consultation = Consultation::create([
            'user_id' => $this->patient->user_id,
            'consultation_date' => now()->toDateString(),
            'attended_by' => $attendedBy->user_id,
            'chief_complaint' => 'Cough',
            'status' => 'ongoing',
        ]);
        DB::table('consultations')->where('id', $consultation->id)->update(['rhu_id' => $rhuId]);

        return $consultation->fresh();
    }

    private function prescription(int $rhuId, User $prescriber, string $number): int
    {
        return DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $this->patient->residentProfile->id,
            'prescribed_by' => $prescriber->user_id,
            'prescription_number' => $number,
            'rhu_id' => $rhuId,
            'prescription_date' => now()->toDateString(),
            'medications' => json_encode([['name' => 'Paracetamol', 'dosage' => '500mg']]),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
