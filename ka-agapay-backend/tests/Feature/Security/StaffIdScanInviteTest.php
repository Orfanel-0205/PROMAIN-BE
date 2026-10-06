<?php
// tests/Feature/Security/StaffIdScanInviteTest.php
//
// The Employee-ID scan on the staff sign-up form runs the photo through
// OCR.space, on the RHU's credit. It needs no sign-in (the person has no
// account yet), so until October 2026 anyone on the internet could run it,
// 20 a minute from each address. It now needs the same signed invite as the
// sign-up itself: checked, not used up.

namespace Tests\Feature\Security;

use App\Services\RegistrationInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StaffIdScanInviteTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/register/extract-employee-id';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ocr_space.key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'ParsedResults' => [['ParsedText' => "REPUBLIC OF THE PHILIPPINES\nJUAN DELA CRUZ\nEMPLOYEE NO. 12345"]],
                'IsErroredOnProcessing' => false,
            ]),
        ]);
    }

    public function test_without_an_invite_nothing_is_scanned(): void
    {
        $this->post(self::URL, ['employee_id' => $this->photo()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'missing_invite');

        Http::assertNothingSent();
    }

    public function test_a_tampered_invite_is_refused(): void
    {
        $invite = $this->invite();
        $invite['invite_signature'] = str_repeat('0', 64);

        $this->post(self::URL, ['employee_id' => $this->photo()] + $invite, ['Accept' => 'application/json'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_with_a_valid_invite_the_photo_is_scanned_and_the_invite_still_works(): void
    {
        $invite = $this->invite();

        $this->post(self::URL, ['employee_id' => $this->photo()] + $invite, ['Accept' => 'application/json'])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ocr.space'));

        // Checked, not used up: the person can still sign up with it.
        $check = app(RegistrationInviteService::class)->validateParams([
            'token' => $invite['invite_token'],
            'expires' => $invite['invite_expires'],
            'signature' => $invite['invite_signature'],
        ]);
        $this->assertTrue($check['ok']);
    }

    /** @return array{invite_token: string, invite_expires: string, invite_signature: string} */
    private function invite(): array
    {
        $link = app(RegistrationInviteService::class)->generate();
        parse_str((string) parse_url($link['signed_api_url'], PHP_URL_QUERY), $query);

        return [
            'invite_token' => (string) $query['token'],
            'invite_expires' => (string) $query['expires'],
            'invite_signature' => (string) $query['signature'],
        ];
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('employee-id.jpg', 600, 380);
    }
}
