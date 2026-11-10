<?php
// tests/Feature/Signature/StaffSignatureTest.php
//
// The MHO's and doctors' signatures on prescriptions, lab requests and ITRs.
//
//   - only the MHO and doctors save one, and only with their password
//   - a drawing keeps its strokes; a photo of paper loses the paper
//   - a blank pad or a non-picture is refused
//   - a document shows the version in force when it was issued: none for one
//     issued before the first signature, the old one for one issued before a
//     replacement, and still the old one after the signature is removed
//   - the prescription and lab request PDFs carry it, a controlled-drug
//     prescription never does, and the lab request stays one page
//   - the ITR is signed by whoever completed the record, not the nurse who
//     opened it

namespace Tests\Feature\Signature;

use App\Models\Consultation;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Support\StaffSignatures;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $mho;
    private User $doctor;
    private User $nurse;
    private User $patient;
    private int $profileId;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->seed(\Database\Seeders\UserRoleSeeder::class);

        $this->mho = $this->makeUser('mho');
        $this->doctor = $this->makeUser('doctor');
        $this->nurse = $this->makeUser('nurse');
        $this->patient = $this->makeUser('resident');
        $this->profileId = ResidentProfile::create([
            'user_id' => $this->patient->user_id,
            'birth_date' => '1990-05-05',
        ])->getKey();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- saving

    public function test_an_mho_saves_a_drawn_signature_with_their_password(): void
    {
        $this->saveAs($this->mho, $this->drawnSignature())
            ->assertCreated()
            ->assertJsonPath('signature.source', 'drawn');

        $row = DB::table('staff_signatures')->where('user_id', $this->mho->user_id)->sole();
        Storage::disk('private')->assertExists($row->path);

        $image = imagecreatefromstring(Storage::disk('private')->get($row->path));
        $this->assertLessThanOrEqual(600, imagesx($image));
        $this->assertLessThanOrEqual(200, imagesy($image));
        $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 0x7F, 'The background stays transparent.');

        $this->assertTrue(
            DB::table('audit_logs')->where('user_id', $this->mho->user_id)->where('action', 'signature.saved')->exists()
        );
    }

    public function test_a_doctor_and_the_super_admin_can_save_one_and_a_nurse_cannot(): void
    {
        $this->saveAs($this->doctor, $this->drawnSignature())->assertCreated();
        $this->saveAs($this->makeUser('super_admin'), $this->drawnSignature())->assertCreated();
        $this->saveAs($this->nurse, $this->drawnSignature())->assertForbidden();

        $this->assertSame(0, DB::table('staff_signatures')->where('user_id', $this->nurse->user_id)->count());
    }

    public function test_the_wrong_password_saves_nothing(): void
    {
        $this->saveAs($this->mho, $this->drawnSignature(), 'not-my-password')
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertSame(0, DB::table('staff_signatures')->count());
    }

    public function test_a_blank_pad_or_a_file_that_is_not_a_picture_is_refused(): void
    {
        $this->saveAs($this->mho, $this->png(400, 150, fn () => null))
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->actingAs($this->mho)
            ->post('/api/v1/admin/profile/signature', [
                'image' => UploadedFile::fake()->createWithContent('sig.png', 'not a picture'),
                'source' => 'uploaded',
                'current_password' => 'password',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('staff_signatures')->count());
    }

    public function test_a_photo_of_a_signature_on_paper_loses_the_paper(): void
    {
        // Grey, unevenly lit paper with dark ink, as a phone photo would be.
        $photo = imagecreatetruecolor(800, 600);
        imagefilledrectangle($photo, 0, 0, 799, 599, imagecolorallocate($photo, 205, 200, 190));
        imagefilledrectangle($photo, 400, 0, 799, 599, imagecolorallocate($photo, 185, 182, 175));
        $ink = imagecolorallocate($photo, 25, 30, 70);
        imagesetthickness($photo, 5);
        imageline($photo, 150, 350, 350, 250, $ink);
        imageline($photo, 350, 250, 500, 360, $ink);
        imageline($photo, 500, 360, 650, 260, $ink);

        ob_start();
        imagejpeg($photo, null, 90);
        $jpeg = (string) ob_get_clean();

        $this->actingAs($this->mho)
            ->post('/api/v1/admin/profile/signature', [
                'image' => UploadedFile::fake()->createWithContent('signature.jpg', $jpeg),
                'source' => 'uploaded',
                'current_password' => 'password',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $row = DB::table('staff_signatures')->sole();
        $image = imagecreatefromstring(Storage::disk('private')->get($row->path));

        // Cropped to the strokes, with the paper gone.
        $this->assertLessThan(800, imagesx($image));
        $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 0x7F);
        $this->assertSame(127, (imagecolorat($image, imagesx($image) - 1, imagesy($image) - 1) >> 24) & 0x7F);
    }

    public function test_the_preview_shows_the_extracted_signature_and_saves_nothing(): void
    {
        $this->actingAs($this->mho)
            ->post('/api/v1/admin/profile/signature/preview', [
                'image' => UploadedFile::fake()->createWithContent('signature.jpg', $this->paperPhoto(30)),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('image', fn ($image) => str_starts_with($image, 'data:image/png;base64,'));

        $this->assertSame(0, DB::table('staff_signatures')->count());

        $this->actingAs($this->nurse)
            ->post('/api/v1/admin/profile/signature/preview', [
                'image' => UploadedFile::fake()->createWithContent('signature.jpg', $this->paperPhoto(30)),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_a_lower_strictness_picks_up_faint_ink(): void
    {
        // Pencil-grey strokes only 35 levels darker than the paper.
        $faint = $this->paperPhoto(170);

        $this->actingAs($this->mho)
            ->post('/api/v1/admin/profile/signature/preview', [
                'image' => UploadedFile::fake()->createWithContent('faint.jpg', $faint),
                'strictness' => 60,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($this->mho)
            ->post('/api/v1/admin/profile/signature/preview', [
                'image' => UploadedFile::fake()->createWithContent('faint.jpg', $faint),
                'strictness' => 25,
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    // ---------------------------------------------------------------- versions

    public function test_a_document_shows_the_signature_in_force_when_it_was_issued(): void
    {
        $id = $this->mho->user_id;

        Carbon::setTestNow('2026-11-01 08:00:00');
        $before = now()->copy();

        Carbon::setTestNow('2026-11-02 08:00:00');
        $first = StaffSignatures::save($this->mho, $this->drawnSignature(), 'drawn');

        Carbon::setTestNow('2026-11-03 08:00:00');
        $betweenSaves = now()->copy();

        Carbon::setTestNow('2026-11-04 08:00:00');
        $second = StaffSignatures::save($this->mho, $this->drawnSignature(200), 'drawn');

        Carbon::setTestNow('2026-11-05 08:00:00');
        $afterReplace = now()->copy();

        Carbon::setTestNow('2026-11-06 08:00:00');
        StaffSignatures::revoke($this->mho);

        Carbon::setTestNow('2026-11-07 08:00:00');

        $this->assertNull(StaffSignatures::activeAt($id, $before), 'Nothing was signed before the first signature.');
        $this->assertSame($first->id, StaffSignatures::activeAt($id, $betweenSaves)?->id);
        $this->assertSame($second->id, StaffSignatures::activeAt($id, $afterReplace)?->id);
        $this->assertNull(StaffSignatures::activeAt($id, now()), 'Removed: new documents are unsigned.');

        $this->assertSame(2, DB::table('staff_signatures')->where('user_id', $id)->count(), 'Old versions are kept.');
    }

    public function test_removing_needs_the_password(): void
    {
        StaffSignatures::save($this->mho, $this->drawnSignature(), 'drawn');

        $this->actingAs($this->mho)
            ->deleteJson('/api/v1/admin/profile/signature', ['current_password' => 'wrong'])
            ->assertStatus(422);
        $this->assertNotNull(StaffSignatures::current($this->mho->user_id));

        $this->actingAs($this->mho)
            ->deleteJson('/api/v1/admin/profile/signature', ['current_password' => 'password'])
            ->assertOk();
        $this->assertNull(StaffSignatures::current($this->mho->user_id));

        $this->actingAs($this->mho)
            ->getJson('/api/v1/admin/profile/signature')
            ->assertOk()
            ->assertJsonPath('signature', null);
    }

    // ---------------------------------------------------------------- documents

    public function test_the_prescription_pdf_carries_the_prescribers_signature(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $unsigned = $this->prescription();

        Carbon::setTestNow('2026-11-02 08:00:00');
        StaffSignatures::save($this->doctor, $this->drawnSignature(), 'drawn');

        Carbon::setTestNow('2026-11-03 08:00:00');
        $signed = $this->prescription();

        $this->assertGreaterThan(
            $this->imagesIn($this->pdf($unsigned)),
            $this->imagesIn($this->pdf($signed)),
            'The prescription issued after the signature was saved carries it.'
        );
    }

    public function test_a_controlled_drug_prescription_is_never_signed(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $plainUnsigned = $this->prescription();

        Carbon::setTestNow('2026-11-02 08:00:00');
        StaffSignatures::save($this->doctor, $this->drawnSignature(), 'drawn');

        Carbon::setTestNow('2026-11-03 08:00:00');
        $controlled = $this->prescription([
            ['name' => 'Tramadol 50mg', 'quantity' => 10, 'is_controlled' => true],
        ]);

        $this->assertSame($this->imagesIn($this->pdf($plainUnsigned)), $this->imagesIn($this->pdf($controlled)));
    }

    public function test_the_lab_request_carries_the_requesters_signature_on_one_page(): void
    {
        Carbon::setTestNow('2026-11-01 08:00:00');
        $unsigned = $this->prescription([], 'lab_request');

        Carbon::setTestNow('2026-11-02 08:00:00');
        StaffSignatures::save($this->doctor, $this->drawnSignature(), 'drawn');

        Carbon::setTestNow('2026-11-03 08:00:00');
        $signed = $this->prescription([], 'lab_request');

        $pdf = $this->pdf($signed);

        $this->assertGreaterThan($this->imagesIn($this->pdf($unsigned)), $this->imagesIn($pdf));
        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'The lab request stays one page.');
    }

    public function test_the_itr_is_signed_by_whoever_completed_the_record(): void
    {
        Carbon::setTestNow('2026-11-02 08:00:00');
        StaffSignatures::save($this->mho, $this->drawnSignature(), 'drawn');

        Carbon::setTestNow('2026-11-03 08:00:00');
        $completed = Consultation::create([
            'user_id' => $this->patient->user_id,
            'consultation_date' => now()->toDateString(),
            'attended_by' => $this->nurse->user_id,
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $this->mho->user_id,
        ]);
        $open = Consultation::create([
            'user_id' => $this->patient->user_id,
            'consultation_date' => now()->toDateString(),
            'attended_by' => $this->mho->user_id,
            'status' => 'ongoing',
        ]);

        $this->actingAs($this->nurse)
            ->getJson("/api/v1/admin/consultations/{$completed->id}/signature")
            ->assertOk()
            ->assertJsonPath('signature.signer_id', $this->mho->user_id)
            ->assertJsonPath('signature.signer_name', $this->mho->full_name);

        $this->actingAs($this->nurse)
            ->getJson("/api/v1/admin/consultations/{$open->id}/signature")
            ->assertOk()
            ->assertJsonPath('signature', null);
    }

    public function test_completing_a_consultation_records_who_completed_it(): void
    {
        $consultation = Consultation::create([
            'user_id' => $this->patient->user_id,
            'consultation_date' => now()->toDateString(),
            'attended_by' => $this->nurse->user_id,
            'status' => 'ongoing',
            'subjective' => 'Fever for two days',
            'assessment' => 'Viral fever',
            'plan' => 'Paracetamol, fluids',
        ]);

        $this->actingAs($this->mho)
            ->patchJson("/api/v1/admin/consultations/{$consultation->id}/complete")
            ->assertOk();

        $this->assertSame($this->mho->user_id, (int) $consultation->fresh()->completed_by);
        $this->assertSame($this->nurse->user_id, (int) $consultation->fresh()->attended_by);
    }

    // ---------------------------------------------------------------- helpers

    private function saveAs(User $user, string $png, string $password = 'password')
    {
        return $this->actingAs($user)->post('/api/v1/admin/profile/signature', [
            'image' => UploadedFile::fake()->createWithContent('signature.png', $png),
            'source' => 'drawn',
            'current_password' => $password,
        ], ['Accept' => 'application/json']);
    }

    /** Dark strokes on a transparent pad, as the admin's drawing pad sends. */
    private function drawnSignature(int $offset = 0): string
    {
        return $this->png(700, 260, function ($image) use ($offset) {
            $ink = imagecolorallocatealpha($image, 15, 23, 42, 0);
            imagesetthickness($image, 4);
            imageline($image, 60 + $offset / 4, 180, 220, 70, $ink);
            imageline($image, 220, 70, 330, 190, $ink);
            imageline($image, 330, 190, 520, 90, $ink);
        });
    }

    /** A JPEG of strokes in grey level $ink on paper of level 205. */
    private function paperPhoto(int $ink): string
    {
        $photo = imagecreatetruecolor(800, 600);
        imagefilledrectangle($photo, 0, 0, 799, 599, imagecolorallocate($photo, 205, 205, 205));
        $color = imagecolorallocate($photo, $ink, $ink, $ink);
        imagesetthickness($photo, 6);
        imageline($photo, 150, 350, 350, 250, $color);
        imageline($photo, 350, 250, 500, 360, $color);
        imageline($photo, 500, 360, 650, 260, $color);

        ob_start();
        imagejpeg($photo, null, 95);

        return (string) ob_get_clean();
    }

    private function png(int $w, int $h, callable $draw): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);
        $draw($image);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function prescription(array $medications = [], string $formType = 'medicine'): int
    {
        return DB::table('prescriptions')->insertGetId([
            'resident_profile_id' => $this->profileId,
            'prescribed_by' => $this->doctor->user_id,
            'prescription_number' => 'RHU1-RX-SIG-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'rhu_id' => 1,
            'form_type' => $formType,
            'prescription_date' => now()->toDateString(),
            'medications' => json_encode($medications ?: [['name' => 'Paracetamol 500mg', 'quantity' => 20]]),
            'lab_tests' => $formType === 'lab_request' ? json_encode(['laboratory' => ['CBC']]) : null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pdf(int $prescriptionId): string
    {
        $response = $this->actingAs($this->doctor)->get("/api/v1/prescriptions/{$prescriptionId}/pdf");
        $response->assertOk();

        return (string) $response->getContent();
    }

    private function imagesIn(string $pdf): int
    {
        return preg_match_all('#/Subtype\s*/Image#', $pdf);
    }

    private function makeUser(string $role): User
    {
        $roleRow = UserRole::firstOrCreate(['name' => $role], ['permissions' => []]);

        return User::create([
            'role_id' => $roleRow->role_id,
            'first_name' => ucfirst($role),
            'last_name' => 'Signer' . ($this->phone + 1),
            'mobile_number' => sprintf('0917400%04d', ++$this->phone),
            'password' => bcrypt('password'),
            'account_status' => 'active',
        ]);
    }
}
