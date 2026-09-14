<?php
// tests/Feature/Security/SensitiveFileAccessTest.php
//
// Prescriptions and ID uploads are readable only by the people entitled to them.
//
// Before this, any logged-in account could list every prescription in the
// system, open any one by id, and download its PDF; PDFs and ID photos were also
// on the web-served public disk. See App\Support\SensitiveFiles.
//
// The rule mirrors patient records:
//   - a prescription: the patient, the prescriber, or staff in the issuing RHU
//     (global-scope accounts, super_admin / MHO, see every RHU)
//   - the staff list is staff-only and RHU-scoped
//   - an OCR result: the uploader, or the registration reviewers
//   - anything refused is a 404, indistinguishable from a missing id

namespace Tests\Feature\Security;

use App\Models\Barangay;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Support\SensitiveFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SensitiveFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $patient;
    private User $otherResident;
    private User $doctor;       // RHU 1, wrote both prescriptions
    private User $nurseRhu1;
    private User $nurseRhu2;
    private User $superAdmin;
    private int $rxRhu1;        // for $patient, issued by RHU 1
    private int $rxRhu2;        // for $otherResident, issued by RHU 2
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        $barangay = Barangay::first();

        $this->patient = $this->makeUser('resident');
        $patientProfile = ResidentProfile::create([
            'user_id'     => $this->patient->user_id,
            'barangay_id' => $barangay->barangay_id,
            'birth_date'  => '1990-05-05',
        ]);

        $this->otherResident = $this->makeUser('resident');
        $otherProfile = ResidentProfile::create([
            'user_id'     => $this->otherResident->user_id,
            'barangay_id' => $barangay->barangay_id,
            'birth_date'  => '1992-06-06',
        ]);

        // users.assigned_rhu_id is a foreign key to barangays, so staff are placed
        // in an RHU through a barangay mapped to it (barangays.rhu_id); see
        // Rhu::resolveRhuIdFromUser. Ids 1 and 2 are skipped because the helper
        // would read those as facility ids directly.
        [$rhu1Barangay, $rhu2Barangay] = Barangay::whereNotIn('barangay_id', [1, 2])
            ->orderBy('barangay_id')
            ->limit(2)
            ->pluck('barangay_id')
            ->all();
        DB::table('barangays')->where('barangay_id', $rhu1Barangay)->update(['rhu_id' => 1]);
        DB::table('barangays')->where('barangay_id', $rhu2Barangay)->update(['rhu_id' => 2]);

        $this->doctor = $this->makeUser('doctor', $rhu1Barangay);
        $this->nurseRhu1 = $this->makeUser('nurse', $rhu1Barangay);
        $this->nurseRhu2 = $this->makeUser('nurse', $rhu2Barangay);
        $this->superAdmin = $this->makeUser('super_admin');

        $this->rxRhu1 = $this->makePrescription($patientProfile->getKey(), 1, 'RHU1-RX-TEST-0001');
        $this->rxRhu2 = $this->makePrescription($otherProfile->getKey(), 2, 'RHU2-RX-TEST-0001');
    }

    // ---------------------------------------------------------------- list

    public function test_residents_cannot_browse_the_staff_prescription_list(): void
    {
        $this->actingAs($this->patient)
            ->getJson('/api/v1/prescriptions')
            ->assertStatus(403);
    }

    public function test_staff_see_only_their_own_rhus_prescriptions(): void
    {
        $ids = $this->listedIds($this->nurseRhu1);

        $this->assertContains($this->rxRhu1, $ids);
        $this->assertNotContains($this->rxRhu2, $ids);

        // Asking for another RHU does not widen a non-global account.
        $ids = $this->listedIds($this->nurseRhu1, '?rhu_id=2');

        $this->assertNotContains($this->rxRhu2, $ids);
    }

    public function test_global_scope_accounts_see_every_rhu(): void
    {
        $ids = $this->listedIds($this->superAdmin);

        $this->assertContains($this->rxRhu1, $ids);
        $this->assertContains($this->rxRhu2, $ids);
    }

    // ---------------------------------------------------------------- one record

    public function test_patient_can_open_their_own_prescription_and_gets_no_public_link(): void
    {
        $response = $this->actingAs($this->patient)
            ->getJson("/api/v1/prescriptions/{$this->rxRhu1}")
            ->assertOk();

        $this->assertNull($response->json('data.pdf_url'));
        $this->assertStringEndsWith("/api/v1/prescriptions/{$this->rxRhu1}/pdf", $response->json('data.pdf_endpoint'));
    }

    public function test_another_resident_gets_404_for_the_record_and_the_pdf(): void
    {
        $this->actingAs($this->otherResident)
            ->getJson("/api/v1/prescriptions/{$this->rxRhu1}")
            ->assertNotFound();

        $this->actingAs($this->otherResident)
            ->get("/api/v1/prescriptions/{$this->rxRhu1}/pdf")
            ->assertNotFound();
    }

    public function test_staff_from_another_rhu_get_404(): void
    {
        $this->actingAs($this->nurseRhu2)
            ->getJson("/api/v1/prescriptions/{$this->rxRhu1}")
            ->assertNotFound();

        $this->actingAs($this->nurseRhu2)
            ->get("/api/v1/prescriptions/{$this->rxRhu1}/pdf")
            ->assertNotFound();

        $this->actingAs($this->nurseRhu1)
            ->getJson("/api/v1/prescriptions/{$this->rxRhu1}")
            ->assertOk();
    }

    public function test_the_prescriber_can_open_their_prescription_from_another_rhu(): void
    {
        // The doctor is RHU 1 but wrote the RHU 2 prescription too.
        $this->actingAs($this->doctor)
            ->getJson("/api/v1/prescriptions/{$this->rxRhu2}")
            ->assertOk();
    }

    public function test_missing_and_forbidden_look_the_same(): void
    {
        $missing = $this->actingAs($this->otherResident)->getJson('/api/v1/prescriptions/999999');
        $forbidden = $this->actingAs($this->otherResident)->getJson("/api/v1/prescriptions/{$this->rxRhu1}");

        $this->assertSame($missing->status(), $forbidden->status());
        $this->assertSame($missing->json('message'), $forbidden->json('message'));
    }

    // ---------------------------------------------------------------- OCR results

    public function test_ocr_results_are_visible_only_to_the_uploader_and_reviewers(): void
    {
        $ocrId = DB::table('ocr_results')->insertGetId([
            'user_id'        => $this->patient->user_id,
            'id_type'        => 'PhilSys National ID',
            'file_path'      => 'ocr/id-verification/' . $this->patient->user_id . '/id.jpg',
            'extracted_text' => 'REYES MARIA 1990-05-05',
            'status'         => 'approved',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->actingAs($this->patient)->getJson("/api/v1/ocr/result/{$ocrId}")->assertOk();
        $this->actingAs($this->otherResident)->getJson("/api/v1/ocr/result/{$ocrId}")->assertNotFound();
        $this->actingAs($this->otherResident)->getJson("/api/v1/ocr/results/{$ocrId}")->assertNotFound();
        $this->actingAs($this->nurseRhu1)->getJson("/api/v1/ocr/result/{$ocrId}")->assertNotFound();
        $this->actingAs($this->superAdmin)->getJson("/api/v1/ocr/result/{$ocrId}")->assertOk();
    }

    // ---------------------------------------------------------------- storage

    public function test_files_not_yet_moved_are_still_found_on_the_legacy_disk(): void
    {
        Storage::fake('public');
        Storage::fake('private');

        Storage::disk('public')->put('ocr/id-verification/7/old.jpg', 'legacy');
        SensitiveFiles::put('ocr/id-verification/7/new.jpg', 'current');

        $this->assertTrue(SensitiveFiles::exists('ocr/id-verification/7/old.jpg'));
        $this->assertTrue(SensitiveFiles::exists('ocr/id-verification/7/new.jpg'));
        $this->assertFalse(SensitiveFiles::exists('ocr/id-verification/7/none.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('ocr/id-verification/7/new.jpg'));
    }

    public function test_privatize_command_moves_only_sensitive_folders_and_dry_run_moves_nothing(): void
    {
        Storage::fake('public');
        Storage::fake('private');

        $public = Storage::disk('public');
        $public->put('ocr/id-verification/7/id.jpg', 'id-photo');
        $public->put('prescriptions/manual/rhu1-rx-0001.pdf', 'pdf');
        $public->put('announcements/banners/banner.jpg', 'banner');

        $this->artisan('storage:privatize-sensitive', ['--dry-run' => true])->assertExitCode(0);

        $this->assertTrue($public->exists('ocr/id-verification/7/id.jpg'));
        $this->assertFalse(SensitiveFiles::disk()->exists('ocr/id-verification/7/id.jpg'));

        $this->artisan('storage:privatize-sensitive')->assertExitCode(0);

        $this->assertFalse($public->exists('ocr/id-verification/7/id.jpg'));
        $this->assertFalse($public->exists('prescriptions/manual/rhu1-rx-0001.pdf'));
        $this->assertSame('id-photo', SensitiveFiles::disk()->get('ocr/id-verification/7/id.jpg'));
        $this->assertSame('pdf', SensitiveFiles::disk()->get('prescriptions/manual/rhu1-rx-0001.pdf'));

        // Banners are meant to be public and stay put.
        $this->assertTrue($public->exists('announcements/banners/banner.jpg'));

        // Running it again is harmless.
        $this->artisan('storage:privatize-sensitive')->assertExitCode(0);
    }

    // ---------------------------------------------------------------- helpers

    private function makeUser(string $role, ?int $assignedBarangayId = null): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        $attributes = [
            'role_id'        => $roleRow->role_id,
            'first_name'     => ucfirst($role),
            'last_name'      => 'Tester' . ($this->phone + 1),
            'mobile_number'  => sprintf('0917200%04d', ++$this->phone),
            'password'       => bcrypt('password'),
            'account_status' => 'active',
        ];

        if ($assignedBarangayId !== null) {
            $attributes['assigned_rhu_id'] = $assignedBarangayId;
        }

        return User::create($attributes);
    }

    private function makePrescription(int $residentProfileId, int $rhuId, string $number): int
    {
        return DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $residentProfileId,
            'prescribed_by'       => $this->doctor->user_id,
            'prescription_number' => $number,
            'rhu_id'              => $rhuId,
            'prescription_date'   => now()->toDateString(),
            'medications'         => json_encode([['name' => 'Paracetamol', 'dosage' => '500mg']]),
            'status'              => 'active',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    /** @return int[] */
    private function listedIds(User $user, string $query = ''): array
    {
        return collect(
            $this->actingAs($user)->getJson('/api/v1/prescriptions' . $query)->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
