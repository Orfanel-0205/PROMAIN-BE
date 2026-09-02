<?php
// tests/Feature/Ocr/OcrVerificationServiceTest.php
//
// Bug 2 — OcrVerificationService.php shipped with no <?php tag.
//
// Two kinds of coverage here, and the second matters more than the first:
//
//   1. Behavioural tests for the service itself, so it is no longer a file that
//      nothing exercises. A class nobody calls is a class nobody notices is
//      broken — that is the whole reason this defect survived to production.
//
//   2. A repo-wide guard (test_every_php_source_file_opens_with_a_php_tag) that
//      fails if ANY file under app/ is missing its opening tag. The one-line fix
//      closes this instance; the guard closes the class of defect. A file like
//      this parses "successfully" as inline HTML, so php -l, composer install
//      and a zip-and-scp deploy all report success while the class silently does
//      not exist and its raw source is echoed into the response body.

namespace Tests\Feature\Ocr;

use App\Models\OcrResult;
use App\Models\RegistrationApproval;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Models\VerificationDocument;
use App\Services\Ocr\OcrVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // The process guard — this is the part that prevents a repeat
    // =====================================================================

    public function test_every_php_source_file_opens_with_a_php_tag(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $handle = fopen($file->getPathname(), 'rb');
            $opening = fread($handle, 5);
            fclose($handle);

            // A UTF-8 BOM before "<?php" breaks headers in exactly the same way
            // a missing tag breaks the body, so treat it as an offender too.
            if (str_starts_with($opening, "\xEF\xBB\xBF")) {
                $offenders[] = $this->relativePath($file->getPathname()) . ' (UTF-8 BOM before <?php)';
                continue;
            }

            if ($opening !== '<?php') {
                $offenders[] = $this->relativePath($file->getPathname()) . ' (does not start with <?php)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These PHP files do not open with a <?php tag. PHP emits everything before the tag\n"
            . "as raw output and never declares the class, so the file deploys cleanly, corrupts\n"
            . "the response body, and then fails with \"class not found\":\n  - "
            . implode("\n  - ", $offenders)
        );
    }

    public function test_the_service_class_actually_loads_and_emits_nothing(): void
    {
        // class_exists() triggers the autoloader, which is where the old file
        // dumped 2,309 bytes of its own source into the output stream.
        ob_start();
        $exists = class_exists(OcrVerificationService::class);
        $leaked = ob_get_clean();

        $this->assertTrue($exists, 'OcrVerificationService must be autoloadable.');
        $this->assertSame('', $leaked, 'Autoloading this class must not emit any output.');
    }

    public function test_the_queued_job_can_resolve_the_service_from_the_container(): void
    {
        // ProcessOcrVerification type-hints this service in handle(). Before the
        // fix this would have thrown a BindingResolutionException the first time
        // the job ever ran.
        $this->assertInstanceOf(
            OcrVerificationService::class,
            app(OcrVerificationService::class)
        );
    }

    // =====================================================================
    // Behavioural coverage
    // =====================================================================

    public function test_a_matching_id_is_approved_and_linked_to_the_registration(): void
    {
        [$user, $document] = $this->makeVerificationDocument('Maria', 'Santos', '1990-04-12');

        $approval = RegistrationApproval::create([
            'user_id' => $user->user_id,
            'status'  => 'pending',
        ]);

        $this->fakeOcrResponse(
            "Republic of the Philippines\n"
            . "Name: SANTOS, MARIA\n"
            . "Date of Birth: April 12, 1990\n"
            . "Address: Malasiqui, Pangasinan\n"
            . "CRN: 1234-5678901-2\n"
        );

        $result = app(OcrVerificationService::class)->processOcr($document);

        $this->assertSame('approved', $result->status);
        $this->assertSame('1990-04-12', $result->extracted_birthdate);
        $this->assertSame('1234-5678901-2', $result->extracted_id_number);
        $this->assertEqualsWithDelta(1.0, (float) $result->name_match_score, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $result->date_match_score, 0.01);

        $this->assertSame(
            $result->id,
            $approval->fresh()->ocr_result_id,
            'The approval record must point at the OCR result.'
        );
    }

    public function test_a_mismatched_id_is_recorded_as_failed_rather_than_approved(): void
    {
        [, $document] = $this->makeVerificationDocument('Maria', 'Santos', '1990-04-12');

        $this->fakeOcrResponse(
            "Name: DELA CRUZ, JUAN\n"
            . "Date of Birth: January 2, 1975\n"
        );

        $result = app(OcrVerificationService::class)->processOcr($document);

        $this->assertSame('failed', $result->status);
        $this->assertLessThan(0.6, (float) $result->overall_match);
    }

    public function test_a_provider_outage_fails_the_record_instead_of_throwing(): void
    {
        [, $document] = $this->makeVerificationDocument('Maria', 'Santos', '1990-04-12');

        Http::fake(['api.ocr.space/*' => Http::response('gateway timeout', 504)]);

        // The queued job has retries; a thrown exception there would be retried
        // three times and then vanish. Recording the failure is what lets a
        // reviewer see that the scan was attempted and did not work.
        $result = app(OcrVerificationService::class)->processOcr($document);

        $this->assertSame('failed', $result->status);
        $this->assertNotNull($result->processed_at);
        $this->assertStringContainsString('504', json_encode($result->raw_ocr_response));
    }

    public function test_a_missing_api_key_is_reported_and_never_silently_passes(): void
    {
        [, $document] = $this->makeVerificationDocument('Maria', 'Santos', '1990-04-12');

        config(['services.ocr_space.key' => '']);
        Http::fake();

        $result = app(OcrVerificationService::class)->processOcr($document);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString(
            'OCR_SPACE_API_KEY',
            json_encode($result->raw_ocr_response)
        );

        Http::assertNothingSent();
    }

    public function test_name_matching_ignores_word_order_and_middle_initials(): void
    {
        [, $document] = $this->makeVerificationDocument('Maria Clara', 'Santos', '1990-04-12');

        // Philippine IDs print surname-first; registration collects given-first.
        // Scoring these as a mismatch would reject a correct ID.
        $this->fakeOcrResponse(
            "Name: SANTOS, MARIA CLARA B.\n"
            . "Date of Birth: 1990-04-12\n"
        );

        $result = app(OcrVerificationService::class)->processOcr($document);

        $this->assertEqualsWithDelta(1.0, (float) $result->name_match_score, 0.01);
        $this->assertSame('approved', $result->status);
    }

    // =====================================================================

    private function makeVerificationDocument(
        string $firstName,
        string $lastName,
        string $birthDate
    ): array {
        $this->seed(\Database\Seeders\UserRoleSeeder::class);
        $this->seed(\Database\Seeders\BarangaySeeder::class);

        config(['services.ocr_space.key' => 'test-key-not-a-real-credential']);

        Storage::fake('local');
        Storage::disk('local')->put('ids/sample.jpg', 'not-a-real-image');
        Storage::disk('local')->put('ids/selfie.jpg', 'not-a-real-image');

        $role = UserRole::where('name', 'resident')->first();

        $user = User::create([
            'role_id'        => $role->role_id,
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'mobile_number'  => '0917' . random_int(1000000, 9999999),
            'password'       => bcrypt('password'),
            'account_status' => 'pending',
        ]);

        ResidentProfile::create([
            'user_id'     => $user->user_id,
            'barangay_id' => \App\Models\Barangay::first()->barangay_id,
            'birth_date'  => $birthDate,
        ]);

        // selfie_path and residency_path are NOT NULL in the migration.
        $document = VerificationDocument::create([
            'user_id'        => $user->user_id,
            'id_photo_path'  => 'ids/sample.jpg',
            'selfie_path'    => 'ids/selfie.jpg',
            'residency_path' => 'ids/selfie.jpg',
            'id_type'        => 'philhealth',
            'submitted_at'   => now(),
        ]);

        return [$user, $document];
    }

    private function fakeOcrResponse(string $parsedText): void
    {
        Http::fake([
            'api.ocr.space/*' => Http::response([
                'ParsedResults' => [['ParsedText' => $parsedText]],
                'IsErroredOnProcessing' => false,
            ], 200),
        ]);
    }

    private function relativePath(string $absolute): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $absolute);
    }
}
